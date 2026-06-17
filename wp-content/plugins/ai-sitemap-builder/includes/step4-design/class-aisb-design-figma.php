<?php

if (!defined('ABSPATH')) exit;

/**
 * Figma-export AJAX en alle Bricks element-verwerkingshelpers voor stap 4.
 */
class AISB_Design_Figma {

  /**
   * AJAX: Exporteer alle design-data als één JSON voor de Figma-plugin.
   */
  public function ajax_export_figma_json(): void {
    $this->require_login();
    $this->check_nonce();

    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    if (!$project_id) wp_send_json_error(['message' => 'Missing project_id'], 400);

    $this->assert_project_ownership($project_id);

    // Bricks global classes — build ID→class map once for the entire export
    $global_classes_raw = get_option('bricks_global_classes', []);
    if (!is_array($global_classes_raw)) $global_classes_raw = [];
    $class_map = [];
    foreach ($global_classes_raw as $gc) {
      if (!empty($gc['id'])) $class_map[$gc['id']] = $gc;
    }

    $guide_raw   = (string) get_post_meta($project_id, 'aisb_style_guide', true);
    $style_guide = $guide_raw ? json_decode($guide_raw, true) : [];
    if (!is_array($style_guide)) $style_guide = [];

    $color_map = $this->_build_color_map($style_guide);

    $sitemap_id = (int) get_post_meta($project_id, 'aisb_latest_sitemap_id', true);
    if (!$sitemap_id) {
      wp_send_json_success(['export' => $this->_build_figma_export($project_id, $style_guide, [])]);
      return;
    }

    $sitemap_json  = (string) get_post_meta($sitemap_id, 'aisb_sitemap_json', true);
    $sitemap_data  = $sitemap_json ? json_decode($sitemap_json, true) : [];
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

        // Saniteer bestaande img-patches waarbij src per ongeluk een array is.
        foreach ($patch_data as &$op) {
          if (isset($op['type']) && $op['type'] === 'img' && isset($op['src']) && is_array($op['src'])) {
            $op['src'] = (string)($op['src']['url'] ?? $op['src']['src'] ?? $op['src']['full'] ?? '');
          }
        }
        unset($op);

        $post_id_for_content = $ai_id ?: $tmpl_id;
        $bricks_elements     = [];
        if ($post_id_for_content) {
          foreach (['_bricks_page_content_2', '_bricks_data', '_bricks_page_header_2', '_bricks_page_footer_2'] as $meta_key) {
            $raw = get_post_meta($post_id_for_content, $meta_key, true);
            if (is_array($raw) && !empty($raw)) { $bricks_elements = $raw; break; }
            if (is_string($raw) && $raw !== '') {
              $decoded = json_decode($raw, true);
              if (is_array($decoded) && !empty($decoded)) { $bricks_elements = $decoded; break; }
            }
          }
        }

        $this->_sanitize_local_logo_urls($bricks_elements);
        $this->_resolve_global_classes($bricks_elements, $class_map);
        $this->_resolve_color_vars($bricks_elements, $color_map);
        $this->_apply_inherited_styles($bricks_elements);
        $this->_normalise_border_radius_settings($bricks_elements);

        // Bewaar structuur vóór Figma-specifieke wijzigingen (voor Bricks publish).
        $bricks_elements_bricks = $bricks_elements;

        $this->_expand_accordion_items($bricks_elements);
        $this->_expand_dropdown_items($bricks_elements);
        $this->_expand_form_elements($bricks_elements);

        $texts = []; $images = []; $text_styles = []; $element_styles = []; $border_radii = [];
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

        $image_count = $this->_count_bricks_image_elements($bricks_elements);

        $export_sections[] = [
          'uuid'                   => $s['uuid'] ?? '',
          'type'                   => $s['type'] ?? 'generic',
          'layout_key'             => $s['layout_key'] ?? '',
          'bricks_template_id'     => $tmpl_id,
          'ai_wireframe_id'        => $ai_id,
          'patch'                  => $patch_data,
          'image_count'            => $image_count,
          'bg_index'               => isset($s['bg_index']) ? (int)$s['bg_index'] : null,
          'bricks_elements'        => $bricks_elements,
          'bricks_elements_bricks' => $bricks_elements_bricks,
          'content'                => [
            'texts'          => $texts,
            'text_styles'    => $text_styles,
            'text_colors'    => array_map(static function ($style) {
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

  /* ------------------- Export builder ------------------- */

  private function _build_figma_export(int $project_id, array $style_guide, array $pages): array {
    $global_classes = get_option('bricks_global_classes', []);
    if (!is_array($global_classes)) $global_classes = [];

    $theme_styles_query = get_posts([
      'post_type'      => 'bricks_theme_style',
      'post_status'    => 'publish',
      'posts_per_page' => -1,
    ]);
    $theme_styles = [];
    foreach ($theme_styles_query as $ts) {
      $settings = get_post_meta($ts->ID, '_bricks_theme_style_settings', true);
      if (empty($settings) || !is_array($settings)) {
        $content = json_decode($ts->post_content, true);
        if (is_array($content)) $settings = $content;
      }
      if (is_array($settings)) {
        $theme_styles[] = ['id' => $ts->ID, 'title' => $ts->post_title, 'settings' => $settings];
      }
    }

    $color_map = $this->_build_color_map($style_guide);

    if (!empty($style_guide['logoUrl'])) {
      $style_guide['logoUrl'] = $this->url_to_data_uri($style_guide['logoUrl']);
    }
    if (!empty($style_guide['uploadedImages']) && is_array($style_guide['uploadedImages'])) {
      foreach ($style_guide['uploadedImages'] as &$img) {
        if (!empty($img['thumb'])) $img['thumb'] = $this->url_to_data_uri($img['thumb']);
        if (!empty($img['full']))  $img['full']  = $this->url_to_data_uri($img['full']);
      }
      unset($img);
    }

    foreach ($global_classes as &$gc) {
      if (!empty($gc['settings']) && is_array($gc['settings'])) {
        $this->_resolve_color_vars($gc['settings'], $color_map);
      }
    }
    unset($gc);

    foreach ($pages as &$page) {
      if (is_array($page)) $this->_resolve_color_vars($page, $color_map);
    }
    unset($page);

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

    $this->_resolve_color_vars($theme_styles, $color_map);

    $global_variables            = get_option('bricks_global_variables', []);
    if (!is_array($global_variables)) $global_variables = [];
    $global_variables_categories = get_option('bricks_global_variables_categories', []);
    if (!is_array($global_variables_categories)) $global_variables_categories = [];
    $color_palette               = get_option('bricks_color_palette', []);
    if (!is_array($color_palette)) $color_palette = [];

    return [
      'version'                    => '1.1',
      'exported_at'                => gmdate('c'),
      'project_id'                 => $project_id,
      'project_name'               => get_the_title($project_id),
      'style_guide'                => $style_guide,
      'global_classes'             => $global_classes,
      'theme_styles'               => $theme_styles,
      'global_variables'           => $global_variables,
      'global_variables_categories' => $global_variables_categories,
      'color_palette'              => $color_palette,
      'pages'                      => $pages,
    ];
  }

  /* ------------------- Color helpers ------------------- */

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
        $hex = $this->_normalise_hex_color((string)$color['hex']);
        if (!$hex) continue;
        if (!empty($color['name']) && is_string($color['name'])) {
          $this->_register_color_token($map, $color['name'], $hex);
        }
        if (isset($fallback_slugs[$index])) {
          $fallback = $fallback_slugs[$index];
          if (!isset($map[$fallback]) && !isset($map['--' . $fallback])) {
            $this->_register_color_token($map, $fallback, $hex);
          }
        }
      }
    }

    if (!isset($map['--base'])) {
      if (isset($map['--dark']))    $this->_register_color_token($map, 'base', $map['--dark']);
      elseif (isset($map['--neutral'])) $this->_register_color_token($map, 'base', $map['--neutral']);
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
    $slug = preg_replace('/^--/', '', (string)$slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', (string)$slug);
    $slug = trim((string)$slug, '-');
    if ($slug === '') return;
    $map['--' . $slug]            = $hex;
    $map['acss_import_' . $slug]  = $hex;
    $this->_register_color_variants($map, $slug, $hex);
  }

  private function _register_color_variants(array &$map, string $slug, string $hex): void {
    if (preg_match('/(?:^|-)(?:trans-\d+|\d+)$/', $slug)) return;
    foreach ([5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 95] as $percent) {
      $t = $this->_hex_with_alpha($hex, $percent);
      $map['--' . $slug . '-' . $percent]             = $map['--' . $slug . '-' . $percent]             ?? $t;
      $map['--' . $slug . '-trans-' . $percent]       = $map['--' . $slug . '-trans-' . $percent]       ?? $t;
      $map['acss_import_' . $slug . '-' . $percent]   = $map['acss_import_' . $slug . '-' . $percent]   ?? $t;
      $map['acss_import_' . $slug . '-trans-' . $percent] = $map['acss_import_' . $slug . '-trans-' . $percent] ?? $t;
    }
    foreach (['ultra-light' => 0.92, 'light' => 0.72] as $suffix => $amount) {
      $v = $this->_mix_hex_colors($hex, '#ffffff', $amount);
      $map['--' . $slug . '-' . $suffix]           = $map['--' . $slug . '-' . $suffix]           ?? $v;
      $map['acss_import_' . $slug . '-' . $suffix] = $map['acss_import_' . $slug . '-' . $suffix] ?? $v;
    }
  }

  private function _normalise_hex_color(string $color): string {
    $color = trim($color);
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color, $match)) {
      $hex = strtolower($match[1]);
      if (strlen($hex) === 3 || strlen($hex) === 4) {
        $expanded = '';
        foreach (str_split($hex) as $char) $expanded .= $char . $char;
        $hex = $expanded;
      }
      return '#' . $hex;
    }
    return '';
  }

  private function _hex_with_alpha(string $hex, int $percent): string {
    $hex = $this->_normalise_hex_color($hex);
    if (!$hex) return '';
    $alpha = max(0, min(255, (int)round(255 * ($percent / 100))));
    return substr($hex, 0, 7) . str_pad(dechex($alpha), 2, '0', STR_PAD_LEFT);
  }

  private function _mix_hex_colors(string $from_hex, string $to_hex, float $amount): string {
    $from = $this->_hex_to_rgb($from_hex);
    $to   = $this->_hex_to_rgb($to_hex);
    if (!$from || !$to) return '';
    $mixed = [];
    for ($i = 0; $i < 3; $i++) {
      $mixed[$i] = (int)round($from[$i] + (($to[$i] - $from[$i]) * $amount));
    }
    return sprintf('#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2]);
  }

  private function _hex_to_rgb(string $hex): array {
    $hex = $this->_normalise_hex_color($hex);
    if (!$hex) return [];
    $base = substr($hex, 1, 6);
    return [hexdec(substr($base, 0, 2)), hexdec(substr($base, 2, 2)), hexdec(substr($base, 4, 2))];
  }

  /* ------------------- Element style resolution ------------------- */

  private function _resolve_color_vars(array &$settings, array $color_map): void {
    foreach ($settings as &$val) {
      if (is_array($val)) {
        if (isset($val['raw']) && is_string($val['raw']) && strpos($val['raw'], 'var(') === 0) {
          $resolved = null;
          if (!empty($val['id'])) {
            $id = strtolower((string)$val['id']);
            if (isset($color_map[$id])) $resolved = $color_map[$id];
          }
          if (!$resolved) {
            preg_match('/var\(\s*(--[^,)\s]+)/', $val['raw'], $matches);
            if (!empty($matches[1]) && isset($color_map[strtolower($matches[1])])) {
              $resolved = $color_map[strtolower($matches[1])];
            }
          }
          if ($resolved) {
            $val['variable'] = $val['raw'];
            $val['raw']      = $resolved;
          }
        }
        $this->_resolve_color_vars($val, $color_map);
      }
    }
    unset($val);
  }

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

  private function _apply_inherited_styles(array &$elements, array $inherited = []): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;
      $settings       = $el['settings'] ?? [];
      $current_inherit = $inherited;
      if (isset($settings['_typography']['color'])) {
        $current_inherit['color'] = $settings['_typography']['color'];
      } elseif (isset($settings['_typography']['color']['hex'])) {
        $current_inherit['color'] = $settings['_typography']['color']['hex'];
      } elseif (isset($settings['_typography']['color']['raw'])) {
        $current_inherit['color'] = $settings['_typography']['color']['raw'];
      }
      if (isset($settings['_typography']['text-align'])) {
        $current_inherit['text-align'] = $settings['_typography']['text-align'];
      }
      $is_text_element = in_array($el['name'] ?? '', ['text', 'heading', 'text-basic', 'button', 'icon', 'rich-text'], true);
      if ($is_text_element) {
        if (!isset($settings['_typography'])) $settings['_typography'] = [];
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

  private function _resolve_backgrounds_to_css(array &$elements): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;
      $settings = $el['settings'] ?? [];
      if (isset($settings['_background']) && is_array($settings['_background']) && !isset($settings['background'])) {
        $bg  = $settings['_background'];
        $css = null;
        if (!empty($bg['color']) && is_array($bg['color'])) {
          $raw = $bg['color']['raw'] ?? $bg['color']['hex'] ?? '';
          if (is_string($raw) && $raw !== '' && strpos($raw, 'var(') !== 0) $css = $raw;
        }
        if ($css === null && !empty($bg['image']['gradient']) && is_string($bg['image']['gradient'])) {
          $css = $bg['image']['gradient'];
        }
        if ($css !== null) $el['settings']['background'] = $css;
      }
      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_resolve_backgrounds_to_css($el['children']);
      }
    }
    unset($el);
  }

  private function _resolve_global_classes(array &$elements, array $class_map): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;
      $class_ids = $el['settings']['_cssGlobalClasses'] ?? [];
      if (!empty($class_ids) && is_array($class_ids)) {
        $merged = [];
        foreach ($class_ids as $cid) {
          if (!empty($class_map[$cid]['settings']) && is_array($class_map[$cid]['settings'])) {
            $merged = array_merge($merged, $class_map[$cid]['settings']);
          }
        }
        if (!empty($merged)) $el['settings'] = array_merge($merged, $el['settings']);
      }
      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_resolve_global_classes($el['children'], $class_map);
      }
    }
    unset($el);
  }

  /* ------------------- URL helper (public for render_design_html) ------------------- */

  public function url_to_data_uri(string $url): string {
    if (empty($url)) return $url;
    $home = untrailingslashit(home_url());
    if (strpos($url, $home) !== 0) return $url;
    $path = wp_normalize_path(untrailingslashit(ABSPATH) . substr($url, strlen($home)));
    if (!is_file($path) || !is_readable($path)) return $url;
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    $content = @file_get_contents($path);
    if ($content === false) return $url;
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    return 'data:' . $mime . ';base64,' . base64_encode($content);
  }

  /* ------------------- Accordion / FAQ expand ------------------- */

  private function _expand_accordion_items(array &$elements, ?string $parent_name = null): void {
    if ($parent_name === null && $this->_is_flat_bricks_element_list($elements)) {
      $this->_expand_flat_accordion_items($elements);
      return;
    }
    foreach ($elements as $i => &$el) {
      if (!is_array($el)) continue;
      $name = $el['name'] ?? '';
      if ($name === 'accordion-nested') {
        $el['name'] = 'block';
        $name = 'block';
        $this->_append_css_class($el, 'aisb-figma-accordion');
      }
      if ($parent_name === 'accordion-nested' && $name === 'block') {
        if (!isset($el['settings'])) $el['settings'] = [];
        $el['settings']['_display']     = 'flex';
        $el['settings']['display']      = 'flex';
        $el['settings']['_direction']   = 'column';
        $el['settings']['flexDirection'] = 'column';
      }
      if ($name === 'accordion' && !empty($el['settings']['items']) && is_array($el['settings']['items'])) {
        $el['name'] = 'block';
        $name = 'block';
        $this->_append_css_class($el, 'aisb-figma-accordion');
        foreach ($el['settings']['items'] as &$item) {
          if (is_array($item)) $item['open'] = true;
        }
        unset($item);
      }
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
    $index_by_id        = [];
    $children_by_parent = [];
    foreach ($elements as $index => $el) {
      if (!is_array($el)) continue;
      $id = (string)($el['id'] ?? '');
      if ($id !== '') $index_by_id[$id] = $index;
      $parent = (string)($el['parent'] ?? '');
      if ($parent !== '' && $parent !== '0') $children_by_parent[$parent][] = $id;
    }

    foreach ($elements as $index => &$el) {
      if (!is_array($el)) continue;
      $name = $el['name'] ?? '';
      if ($name === 'accordion-nested') {
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
          $elements[$item_index]['settings']['_display']     = 'flex';
          $elements[$item_index]['settings']['display']      = 'flex';
          $elements[$item_index]['settings']['_direction']   = 'column';
          $elements[$item_index]['settings']['flexDirection'] = 'column';
          foreach ($this->_flat_descendant_ids($item_id, $children_by_parent) as $descendant_id) {
            if (!isset($index_by_id[$descendant_id])) continue;
            $this->_expand_accordion_descendant($elements[$index_by_id[$descendant_id]]);
          }
        }
      }
      if ($name === 'accordion' && !empty($el['settings']['items']) && is_array($el['settings']['items'])) {
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
      foreach ($children_by_parent[$accordion_id] as $child_id) $ids[] = (string)$child_id;
    }
    return array_values(array_unique(array_filter($ids, static function ($id) { return $id !== ''; })));
  }

  private function _flat_descendant_ids(string $parent_id, array $children_by_parent): array {
    $result = [];
    $stack  = $children_by_parent[$parent_id] ?? [];
    while ($stack) {
      $id = (string)array_shift($stack);
      if ($id === '' || in_array($id, $result, true)) continue;
      $result[] = $id;
      if (!empty($children_by_parent[$id])) {
        foreach ($children_by_parent[$id] as $child_id) $stack[] = (string)$child_id;
      }
    }
    return $result;
  }

  private function _expand_accordion_descendant(array &$el): void {
    if (!isset($el['settings']) || !is_array($el['settings'])) $el['settings'] = [];
    $settings     = &$el['settings'];
    $class_string = $this->_settings_class_string($settings);
    $is_content   = strpos($class_string, 'accordion-content-wrapper') !== false;
    $is_hidden    = !$is_content && !empty($settings['_hidden']) && is_array($settings['_hidden']);
    if ($is_content || $is_hidden) {
      if ($is_content) $this->_remove_css_class($el, 'accordion-content-wrapper');
      $this->_append_css_class($el, 'aisb-figma-expanded-content');
      $settings['_display']    = 'flex';   $settings['display']     = 'flex';
      $settings['_direction']  = 'column'; $settings['flexDirection'] = 'column';
      $settings['_visibility'] = 'visible'; $settings['visibility']  = 'visible';
      $settings['_opacity']    = '1';      $settings['opacity']     = '1';
      $settings['_height']     = 'auto';   $settings['height']      = 'auto';
      $settings['_overflow']   = 'visible'; $settings['overflow']    = 'visible';
      $settings['ariaHidden']  = 'false';
      $settings['_cssCustom']  = trim((string)($settings['_cssCustom'] ?? '') . "\n&{display:flex!important;flex-direction:column!important;height:auto!important;opacity:1!important;visibility:visible!important;overflow:visible!important;}");
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

    $intro        = $this->_extract_static_faq_intro($elements, $pairs, $by_id);
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
    $root['id']       = $root_id;
    $root['name']     = 'section';
    $root['parent']   = 0;
    $root['children'] = ['aisb_faq_container_' . $root_id];
    if (!isset($root['settings']) || !is_array($root['settings'])) $root['settings'] = [];
    unset($root['settings']['_cssGlobalClasses']);

    $container_id = 'aisb_faq_container_' . $root_id;
    $left_id      = 'aisb_faq_left_' . $root_id;
    $right_id     = 'aisb_faq_list_' . $root_id;

    $new_elements = [$root];
    $text_styles  = [];
    $texts        = [];

    $new_elements[] = [
      'id' => $container_id, 'name' => 'container', 'parent' => $root_id,
      'children' => [$left_id, $right_id],
      'settings' => [
        '_display' => 'grid', '_gridTemplateColumns' => 'repeat(2, minmax(0, 1fr))',
        '_gridGap' => 'var(--space-m)', '_gridTemplateColumns:tablet_portrait' => 'repeat(1, minmax(0, 1fr))',
        '_padding' => ['top' => '3rem', 'bottom' => '3rem', 'left' => '', 'right' => ''],
      ],
    ];

    $left_children = [];
    foreach ($intro as $idx => $_item) $left_children[] = 'aisb_faq_intro_' . $root_id . '_' . $idx;

    $new_elements[] = [
      'id' => $left_id, 'name' => 'block', 'parent' => $container_id,
      'children' => $left_children, 'label' => 'FAQ Intro',
      'settings' => ['_display' => 'flex', '_direction' => 'column', '_rowGap' => 'var(--space-s)', '_padding' => ['top' => '3rem', 'bottom' => '3rem', 'left' => '', 'right' => '']],
    ];

    foreach ($intro as $idx => $item) {
      $id  = $left_children[$idx];
      $tag = $idx === 0 ? 'h2' : 'p';
      $new_elements[] = $this->_make_static_faq_text_element($id, $left_id, $item['text'], $item['el'], $tag);
      $style          = $this->_next_static_faq_text_style($style_cursor, $item['text'], $item['el'], $idx === 0 ? 'heading' : 'body');
      $texts[]        = $item['text'];
      $text_styles[]  = $style;
    }

    $right_children = [];
    foreach ($pairs as $idx => $_pair) $right_children[] = 'aisb_faq_card_' . $root_id . '_' . $idx;

    $new_elements[] = [
      'id' => $right_id, 'name' => 'block', 'parent' => $container_id,
      'children' => $right_children, 'label' => 'FAQ List',
      'settings' => ['_display' => 'flex', '_direction' => 'column', '_rowGap' => 'var(--space-m)', '_padding' => ['top' => '3rem', 'bottom' => '3rem', 'left' => '', 'right' => '']],
    ];

    foreach ($pairs as $idx => $pair) {
      $card_id     = $right_children[$idx];
      $question_id = 'aisb_faq_q_' . $root_id . '_' . $idx;
      $answer_id   = 'aisb_faq_a_' . $root_id . '_' . $idx;

      $new_elements[] = [
        'id' => $card_id, 'name' => 'block', 'parent' => $right_id,
        'children' => [$question_id, $answer_id], 'label' => 'FAQ Item',
        'settings' => [
          '_display' => 'flex', '_direction' => 'column', '_rowGap' => 'var(--space-xs)',
          '_padding' => ['top' => 'var(--space-s)', 'bottom' => 'var(--space-s)', 'left' => '0', 'right' => '0'],
          '_border'  => ['width' => ['bottom' => '1px'], 'style' => 'solid', 'color' => ['raw' => '#08264533']],
        ],
      ];

      $new_elements[] = $this->_make_static_faq_text_element($question_id, $card_id, $pair['question'], $pair['question_el'], 'h3');
      $new_elements[] = $this->_make_static_faq_text_element($answer_id,   $card_id, $pair['answer'],   $pair['answer_el'],   'p');
      $texts[]        = $pair['question'];
      $text_styles[]  = $this->_next_static_faq_text_style($style_cursor, $pair['question'], $pair['question_el'], 'question');
      $texts[]        = $pair['answer'];
      $text_styles[]  = $this->_next_static_faq_text_style($style_cursor, $pair['answer'],   $pair['answer_el'],   'answer');
    }

    return ['bricks_elements' => $new_elements, 'texts' => $texts, 'text_styles' => $text_styles];
  }

  private function _flat_element_lookup(array $elements): array {
    $by_id = []; $children_by_parent = [];
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
    $pairs = []; $seen = [];
    foreach ($elements as $wrapper) {
      if (!$this->_is_static_faq_answer_wrapper($wrapper)) continue;
      $wrapper_id = (string)($wrapper['id'] ?? '');
      if ($wrapper_id !== '' && isset($seen[$wrapper_id])) continue;
      if ($wrapper_id !== '') $seen[$wrapper_id] = true;

      $answer_ids   = array_merge([$wrapper_id], $this->_flat_descendant_ids($wrapper_id, $children_by_parent));
      $answer_texts = []; $answer_el = $wrapper;
      foreach ($answer_ids as $id) {
        if (empty($by_id[$id]) || !$this->_is_static_text_like_element($by_id[$id])) continue;
        $answer_texts[] = $this->_clean_figma_text($by_id[$id]['settings']['text'] ?? '');
        $answer_el      = $by_id[$id];
      }

      $parent_id = (string)($wrapper['parent'] ?? '');
      $question  = ''; $question_el = [];
      if ($parent_id !== '') {
        $answer_id_map = array_fill_keys($answer_ids, true);
        foreach ($this->_flat_descendant_ids($parent_id, $children_by_parent) as $candidate_id) {
          if (isset($answer_id_map[$candidate_id]) || empty($by_id[$candidate_id])) continue;
          if (!$this->_is_static_faq_question_element($by_id[$candidate_id])) continue;
          $question_el = $by_id[$candidate_id];
          $question    = $this->_clean_figma_text($question_el['settings']['text'] ?? '');
          break;
        }
      }

      $answer_texts = array_values(array_unique(array_filter($answer_texts)));
      $answer       = trim(implode("\n", $answer_texts));
      if ($question === '' || $answer === '') continue;

      $pairs[] = [
        'question' => $question, 'answer' => $answer,
        'question_el' => $question_el, 'answer_el' => $answer_el,
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
      if (!empty($pair['answer_el']['id']))   $used[(string)$pair['answer_el']['id']]   = true;
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
        if ($item['text'] === $text && ($item['el']['name'] ?? '') === ($el['name'] ?? '')) { $duplicate = true; break; }
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
    $settings['text']       = $text;
    $settings['tag']        = $tag;
    $settings['_display']   = 'block'; $settings['display']      = 'block';
    $settings['_visibility']= 'visible'; $settings['visibility'] = 'visible';
    $settings['_opacity']   = '1'; $settings['opacity']          = '1';
    $settings['_overflow']  = 'visible'; $settings['overflow']    = 'visible';
    $settings['_cssCustom'] = trim((string)($settings['_cssCustom'] ?? '') . "\n&{display:block!important;opacity:1!important;visibility:visible!important;overflow:visible!important;}");
    return [
      'id' => $id, 'name' => preg_match('/^h[1-6]$/', $tag) ? 'heading' : 'text-basic',
      'parent' => $parent, 'children' => [], 'settings' => $settings,
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
    $typography  = isset($source_el['settings']['_typography']) && is_array($source_el['settings']['_typography']) ? $source_el['settings']['_typography'] : [];
    $is_heading  = in_array($role, ['heading', 'question'], true);
    $tag         = strtolower((string)($source_el['settings']['tag'] ?? ''));
    $sizes       = ['h1' => '64px', 'h2' => '48px', 'h3' => '36px', 'h4' => '28px', 'h5' => '22px', 'h6' => '18px'];
    $default_size = $is_heading ? ($sizes[$tag] ?? '48px') : '18px';
    return [
      'text'       => $clean,
      'color'      => $this->_raw_color($typography['color'] ?? null) ?: '#082645',
      'fontSize'   => (string)($typography['font-size']   ?? $default_size),
      'fontFamily' => (string)($typography['font-family'] ?? $typography['fontFamily'] ?? ''),
      'fontWeight' => (string)($typography['font-weight'] ?? ($is_heading ? '700' : '400')),
      'lineHeight' => (string)($typography['line-height'] ?? ($is_heading ? '1.12' : '1.6')),
      'textAlign'  => 'start',
    ];
  }

  private function _raw_color($value): string {
    if (is_string($value)) return $value;
    if (is_array($value)) return (string)($value['raw'] ?? ($value['hex'] ?? ''));
    return '';
  }

  private function _is_static_faq_answer_wrapper($el): bool {
    if (!is_array($el)) return false;
    $label    = strtolower((string)($el['label'] ?? ''));
    $settings = isset($el['settings']) && is_array($el['settings']) ? $el['settings'] : [];
    return $label === 'answer wrapper'
      || $this->_has_attribute_value($settings, 'itemprop', 'acceptedAnswer')
      || $this->_has_attribute_value($settings, 'itemtype', 'https://schema.org/Answer');
  }

  private function _is_static_faq_question_element($el): bool {
    if (!$this->_is_static_text_like_element($el)) return false;
    $label    = strtolower((string)($el['label'] ?? ''));
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

  /* ------------------- DOM/CSS class & attribute utilities ------------------- */

  private function _settings_class_string(array $settings): string {
    $hidden = isset($settings['_hidden']) && is_array($settings['_hidden']) ? $settings['_hidden'] : [];
    $parts  = [
      (string)($settings['_cssClasses'] ?? ''), (string)($settings['cssClasses'] ?? ''),
      (string)($settings['class'] ?? ''),       (string)($settings['_class'] ?? ''),
      (string)($hidden['_cssClasses'] ?? ''),   (string)($hidden['cssClasses'] ?? ''),
    ];
    if (!empty($settings['_attributes']) && is_array($settings['_attributes'])) {
      foreach ($settings['_attributes'] as $attr) {
        if (!is_array($attr) || ($attr['name'] ?? '') !== 'class') continue;
        $parts[] = (string)($attr['value'] ?? '');
      }
    }
    return implode(' ', array_filter($parts, static function ($part) { return trim($part) !== ''; }));
  }

  private function _append_css_class(array &$el, string $class): void {
    if (!isset($el['settings']) || !is_array($el['settings'])) $el['settings'] = [];
    $existing = trim((string)($el['settings']['_cssClasses'] ?? ''));
    $classes  = $existing === '' ? [] : preg_split('/\s+/', $existing);
    if (!in_array($class, $classes, true)) $classes[] = $class;
    $el['settings']['_cssClasses'] = trim(implode(' ', $classes));
  }

  private function _remove_css_class(array &$el, string $class): void {
    if (!isset($el['settings']) || !is_array($el['settings'])) return;
    foreach (['_cssClasses', 'cssClasses', '_class', 'class'] as $key) {
      if (empty($el['settings'][$key])) continue;
      $parts = preg_split('/\s+/', (string)$el['settings'][$key]);
      $parts = array_values(array_filter($parts, static function ($c) use ($class) { return $c !== '' && $c !== $class; }));
      $el['settings'][$key] = implode(' ', $parts);
    }
    if (!empty($el['settings']['_hidden']) && is_array($el['settings']['_hidden'])) {
      foreach (['_cssClasses', 'cssClasses'] as $key) {
        if (empty($el['settings']['_hidden'][$key])) continue;
        $parts = preg_split('/\s+/', (string)$el['settings']['_hidden'][$key]);
        $parts = array_values(array_filter($parts, static function ($c) use ($class) { return $c !== '' && $c !== $class; }));
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
    if (!isset($settings['_attributes']) || !is_array($settings['_attributes'])) $settings['_attributes'] = [];
    foreach ($settings['_attributes'] as &$attr) {
      if (!is_array($attr) || ($attr['name'] ?? '') !== $name) continue;
      $attr['value'] = $value;
      unset($attr);
      return;
    }
    unset($attr);
    $settings['_attributes'][] = ['id' => 'aisb_' . substr(md5($name . $value), 0, 8), 'name' => $name, 'value' => $value];
  }

  private function _remove_attribute(array &$settings, string $name): void {
    if (empty($settings['_attributes']) || !is_array($settings['_attributes'])) return;
    $settings['_attributes'] = array_values(array_filter($settings['_attributes'], static function ($attr) use ($name) {
      return !(is_array($attr) && ($attr['name'] ?? '') === $name);
    }));
  }

  /* ------------------- Dropdown / nav-nested expand ------------------- */

  private function _expand_dropdown_items(array &$elements): void {
    if ($this->_is_flat_bricks_element_list($elements)) {
      $this->_expand_flat_dropdown_items($elements);
      return;
    }
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;
      $name = $el['name'] ?? '';
      if (!isset($el['settings']) || !is_array($el['settings'])) $el['settings'] = [];
      if ($name === 'nav-nested') { $el['name'] = 'block'; $this->_append_css_class($el, 'aisb-figma-nav-nested'); }
      if ($name === 'dropdown')   { $el['name'] = 'block'; $this->_append_css_class($el, 'aisb-figma-dropdown'); }
      $class_string = $this->_settings_class_string($el['settings']);
      if (strpos($class_string, 'brx-dropdown-content') !== false || strpos($class_string, 'brx-nav-nested-items') !== false) {
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
      if ($name === 'nav-nested') { $el['name'] = 'block'; $this->_append_css_class($el, 'aisb-figma-nav-nested'); }
      if ($name === 'dropdown')   { $el['name'] = 'block'; $this->_append_css_class($el, 'aisb-figma-dropdown'); }
      $class_string = $this->_settings_class_string($el['settings']);
      if (strpos($class_string, 'brx-dropdown-content') !== false || strpos($class_string, 'brx-nav-nested-items') !== false) {
        $this->_expand_dropdown_content($el);
      }
    }
    unset($el);
  }

  private function _expand_dropdown_content(array &$el): void {
    if (!isset($el['settings']) || !is_array($el['settings'])) $el['settings'] = [];
    $settings = &$el['settings'];
    $this->_remove_css_class($el, 'brx-dropdown-content');
    $this->_remove_css_class($el, 'brx-nav-nested-items');
    $this->_append_css_class($el, 'aisb-figma-expanded-content');
    unset($settings['_hidden']);
    $settings['_display']    = 'block'; $settings['display']     = 'block';
    $settings['_visibility'] = 'visible'; $settings['visibility']= 'visible';
    $settings['_opacity']    = '1'; $settings['opacity']         = '1';
    $settings['_overflow']   = 'visible'; $settings['overflow']  = 'visible';
    $settings['ariaHidden']  = 'false';
    $settings['_cssCustom']  = trim((string)($settings['_cssCustom'] ?? '') . "\n&{display:block!important;opacity:1!important;visibility:visible!important;overflow:visible!important;}");
    $this->_remove_attribute($settings, 'aria-hidden');
    $this->_set_attribute($settings, 'aria-hidden', 'false');
    unset($settings);
  }

  /* ------------------- Form expand ------------------- */

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
        if (!empty($synth)) $el['children'] = array_merge((array)($el['children'] ?? []), $synth);
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
        $el['children'][] = (string)$synth['id'];
      }
    }
    unset($el);
    foreach ($new_elements as $synth_el) $elements[] = $synth_el;
  }

  private function _form_fields_to_elements(array $fields, string $parent_id): array {
    $result = [];
    foreach ($fields as $idx => $field) {
      if (!is_array($field)) continue;
      $type        = $field['type'] ?? 'text';
      $label       = trim((string)($field['label'] ?? ''));
      $placeholder = trim((string)($field['placeholder'] ?? ''));
      $synth_id    = 'aisb_f_' . ($field['id'] ?? $idx);
      if ($type === 'submit') {
        $btn_text = $label ?: $placeholder ?: ($field['value'] ?? 'Verzenden');
        $entry = ['id' => $synth_id, 'name' => 'button', 'settings' => ['text' => $btn_text], 'children' => []];
      } elseif ($label !== '') {
        $entry = ['id' => $synth_id, 'name' => 'text', 'settings' => ['text' => $label], 'children' => []];
      } elseif ($placeholder !== '') {
        $entry = ['id' => $synth_id, 'name' => 'button', 'settings' => ['text' => $placeholder], 'children' => []];
      } else {
        continue;
      }
      if ($parent_id !== '') $entry['parent'] = $parent_id;
      $result[] = $entry;
    }
    return $result;
  }

  /* ------------------- Image & content utilities ------------------- */

  private function _sanitize_local_logo_urls(array &$elements): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;
      if (($el['name'] ?? '') === 'logo' && isset($el['settings']['logo']['url'])) {
        $el['settings']['logo']['url'] = $this->url_to_data_uri($el['settings']['logo']['url']);
      }
      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_sanitize_local_logo_urls($el['children']);
      }
    }
    unset($el);
  }

  private function _count_bricks_image_elements(array $elements): int {
    $count = 0;
    foreach ($elements as $el) {
      if (!is_array($el)) continue;
      $name = $el['name'] ?? '';
      if ($name === 'image') {
        $count++;
      } elseif ($name === 'image-gallery') {
        $items = $el['settings']['items']['images'] ?? [];
        if (is_array($items)) $count += count($items);
      }
      if (!empty($el['children']) && is_array($el['children'])) {
        $count += $this->_count_bricks_image_elements($el['children']);
      }
    }
    return $count;
  }

  private function _extract_figma_content(array $elements, array &$texts, array &$images, array &$text_styles, array &$element_styles, array &$border_radii): void {
    foreach ($elements as $el) {
      if (!is_array($el)) continue;
      $name       = $el['name'] ?? '';
      $settings   = $el['settings'] ?? [];
      $radius_map = $this->_extract_border_radius_map($settings);
      if (!empty($radius_map)) {
        $style_entry = ['id' => (string)($el['id'] ?? ''), 'name' => (string)$name, 'label' => (string)($el['label'] ?? '')];
        foreach ($radius_map as $prop => $value) $style_entry[$prop] = $value;
        $element_styles[] = $style_entry;
        foreach ($radius_map as $prop => $value) {
          if ($prop === '_borderRadius') continue;
          $border_radii[] = ['id' => (string)($el['id'] ?? ''), 'name' => (string)$name, 'label' => (string)($el['label'] ?? ''), 'prop' => (string)$prop, 'value' => (string)$value];
        }
      }
      if (in_array($name, ['text', 'heading', 'text-basic'], true)) {
        $this->_append_figma_text_content($settings['text'] ?? $settings['content'] ?? '', $settings, $texts, $text_styles);
      } elseif ($name === 'image') {
        $src = $settings['image']['url'] ?? $settings['src'] ?? '';
        if ($src) $images[] = (string)$src;
      } elseif ($name === 'button') {
        $this->_append_figma_text_content($settings['text'] ?? $settings['label'] ?? '', $settings, $texts, $text_styles);
      }
      if (!empty($el['children']) && is_array($el['children'])) {
        $this->_extract_figma_content($el['children'], $texts, $images, $text_styles, $element_styles, $border_radii);
      }
    }
  }

  private function _append_figma_text_content($text, array $settings, array &$texts, array &$text_styles): void {
    if ($text === null || $text === '') return;
    $clean_text = wp_strip_all_tags((string)$text);
    if ($clean_text === '') return;
    $texts[] = $clean_text;
    $style   = $this->_extract_figma_text_style($settings);
    $style['text'] = $clean_text;
    $text_styles[] = $style;
  }

  private function _extract_figma_text_style(array $settings): array {
    $typography = (!empty($settings['_typography']) && is_array($settings['_typography'])) ? $settings['_typography'] : [];
    $style      = [];
    $color      = $this->_extract_style_color($typography['color'] ?? ($settings['color'] ?? null));
    if ($color !== '') $style['color'] = $color;
    $radius_map = $this->_extract_border_radius_map($settings);
    if (!empty($radius_map['borderRadius'])) {
      $style['borderRadius']  = $radius_map['borderRadius'];
      $style['_borderRadius'] = $radius_map['borderRadius'];
    }
    foreach (['font-size' => 'fontSize', 'font-family' => 'fontFamily', 'font-weight' => 'fontWeight', 'line-height' => 'lineHeight', 'text-align' => 'textAlign'] as $src => $dst) {
      if (isset($typography[$src]) && $typography[$src] !== '') $style[$dst] = (string)$typography[$src];
    }
    return $style;
  }

  private function _extract_style_color($color): string {
    if (is_string($color)) return $color;
    if (is_array($color)) {
      foreach (['raw', 'hex', 'color', 'value'] as $key) {
        if (!empty($color[$key]) && is_string($color[$key])) return $color[$key];
      }
    }
    return '';
  }

  /* ------------------- Border radius helpers ------------------- */

  private function _normalise_border_radius_settings(array &$elements): void {
    foreach ($elements as &$el) {
      if (!is_array($el)) continue;
      if (!empty($el['settings']) && is_array($el['settings'])) {
        $radius_map = $this->_extract_border_radius_map($el['settings']);
        if (!empty($radius_map['borderRadius'])) {
          $el['settings']['_borderRadius'] = $radius_map['borderRadius'];
          $el['settings']['borderRadius']  = $radius_map['borderRadius'];
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
      $selector         = (string)($op['selector'] ?? '');
      $element_styles[] = ['selector' => $selector, 'source' => 'patch', 'borderRadius' => $value, '_borderRadius' => $value];
      $border_radii[]   = ['selector' => $selector, 'source' => 'patch', 'prop' => 'borderRadius', 'value' => $value];
    }
  }

  private function _extract_border_radius_map(array $settings): array {
    $radii  = [];
    $direct = '';
    foreach (['_borderRadius', 'borderRadius', 'border-radius'] as $key) {
      if (array_key_exists($key, $settings)) {
        $direct = $this->_normalise_border_radius_value($settings[$key]);
        if ($direct !== '') break;
      }
    }
    if ($direct === '' && isset($settings['_border']['radius'])) {
      $direct = $this->_normalise_border_radius_value($settings['_border']['radius']);
    }
    if ($direct !== '') { $radii['borderRadius'] = $direct; $radii['_borderRadius'] = $direct; }
    foreach ($settings as $key => $value) {
      if (!is_array($value) || $key === '_border' || !array_key_exists('radius', $value)) continue;
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
    $parts = array_values(array_filter(preg_split('/[^a-zA-Z0-9]+/', $key), static function ($p) { return $p !== ''; }));
    if (empty($parts)) return 'borderRadius';
    $camel = array_shift($parts);
    foreach ($parts as $part) $camel .= ucfirst($part);
    return $camel . 'Radius';
  }

  private function _normalise_border_radius_value($value): string {
    if (is_int($value) || is_float($value)) {
      $number = is_int($value) ? (string)$value : rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
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
        $n = $this->_normalise_border_radius_value($value[$key]);
        if ($n !== '') return $n;
      }
    }
    $keys   = ['top', 'right', 'bottom', 'left'];
    $values = [];
    foreach ($keys as $key) {
      $values[] = array_key_exists($key, $value) ? $this->_normalise_border_radius_value($value[$key]) : '';
    }
    if (implode('', $values) === '') {
      $values = [];
      foreach (['topLeft', 'topRight', 'bottomRight', 'bottomLeft'] as $key) {
        $values[] = array_key_exists($key, $value) ? $this->_normalise_border_radius_value($value[$key]) : '';
      }
    }
    if (implode('', $values) === '') return '';
    $fallback = '';
    foreach ($values as $r) { if ($r !== '') { $fallback = $r; break; } }
    if ($fallback === '') return '';
    $values = array_map(static function ($r) use ($fallback) { return $r !== '' ? $r : $fallback; }, $values);
    if ($values[0] === $values[1] && $values[0] === $values[2] && $values[0] === $values[3]) return $values[0];
    if ($values[0] === $values[2] && $values[1] === $values[3]) return $values[0] . ' ' . $values[1];
    if ($values[1] === $values[3]) return $values[0] . ' ' . $values[1] . ' ' . $values[2];
    return implode(' ', $values);
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
