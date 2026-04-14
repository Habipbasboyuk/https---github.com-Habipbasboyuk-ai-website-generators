<?php

if (!defined('ABSPATH')) exit;

/**
 * AI-powered content population for Bricks templates.
 * Sends placeholder texts to OpenAI and creates new template posts with generated copy.
 */
class AISB_Wireframes_AI {

  /**
   * Replace placeholder texts in Bricks template sections with AI-generated copy.
   * Creates new bricks_template posts so originals are untouched.
   */
  public function populate_bricks_content_with_ai(array $model, int $project_id, int $sitemap_version_id, string $page_slug): array {
    $brief = (string) get_post_meta($project_id, 'aisb_project_brief', true);
    $sitemap_json = get_post_meta($sitemap_version_id, 'aisb_sitemap_json', true);
    $sitemap_data = json_decode((string)$sitemap_json, true) ?: [];

    $sections_with_id = array_filter($model['sections'] ?? [], fn($s) => !empty($s['bricks_template_id']));
    error_log('[AISB] populate_bricks_content_with_ai START: page=' . $page_slug . ' total_sections=' . count($model['sections'] ?? []) . ' sections_with_bricks_id=' . count($sections_with_id));
    
    // 1. Find the page info in the sitemap
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
    $text_keys = ['text', 'title', 'subtitle', 'description', 'heading', 'content', 'label'];
    
    $to_translate = [];
    $raw_bricks_data_maps = [];

    // 2. Collect all text from chosen wireframe templates
    foreach ($model['sections'] as $idx => $sec) {
        if (empty($sec['bricks_template_id'])) {
            error_log('[AISB] Section ' . $idx . ' (type=' . ($sec['type'] ?? '?') . ') has no bricks_template_id — skipped');
            continue;
        }
        
        $post_id = (int) $sec['bricks_template_id'];
        $bricks_data = get_post_meta($post_id, '_bricks_page_content_2', true);
        if (!is_array($bricks_data)) {
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

    // 3. Send to OpenAI
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

    // 4. Create new templates with AI text
    foreach ($translated as $sec_key => $data) {
        $idx = (int) str_replace('section_', '', $sec_key);
        if (!isset($raw_bricks_data_maps[$idx])) continue;
        $original_id = (int)($model['sections'][$idx]['bricks_template_id'] ?? 0);
        $cloned_data = $raw_bricks_data_maps[$idx];

        foreach ($cloned_data as &$node) {
            $nid = $node['id'];
            if (isset($data['modules'][$nid]['settings'])) {
                foreach ($data['modules'][$nid]['settings'] as $key => $new_text) {
                    $node['settings'][$key] = $new_text;
                }
            }
        }
        unset($node);

        $new_post_id = wp_insert_post([
            'post_title'  => "[AI] " . $page_title . " - Section " . $idx,
            'post_type'   => 'bricks_template',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($new_post_id) || !$new_post_id) {
            error_log('[AISB] wp_insert_post FAILED for section ' . $idx . ': ' . (is_wp_error($new_post_id) ? $new_post_id->get_error_message() : 'returned 0'));
        } else {
            update_post_meta($new_post_id, '_bricks_page_content_2', $cloned_data);

            $original_type = $original_id ? get_post_meta($original_id, '_bricks_template_type', true) : '';
            if (!$original_type) {
                $original_type = 'section';
            }
            update_post_meta($new_post_id, '_bricks_template_type', $original_type);

            $taxonomies = ['template_type', 'template_tag'];
            foreach ($taxonomies as $tax) {
                $terms = $original_id ? wp_get_object_terms($original_id, $tax, ['fields' => 'slugs']) : [];
                if (!empty($terms) && !is_wp_error($terms)) {
                    wp_set_object_terms($new_post_id, $terms, $tax);
                }
            }

            // Tag this template as AI-generated in the Bricks template_tag taxonomy
            wp_set_object_terms($new_post_id, ['ai-generated'], 'template_tag', true);

            $model['sections'][$idx]['bricks_template_id'] = $new_post_id;
            $model['sections'][$idx]['bricks_shortcode'] = '[bricks_template id="' . $new_post_id . '"]';
            error_log('[AISB] Created AI Bricks template post ID=' . $new_post_id . ' for section ' . $idx . ' (type=' . $original_type . ')');
        }
    }

    return $model;
  }

  /** Strip markdown code fences from AI response. */
  public function clean_json_response($string) {
    return preg_replace('/^```json|```$/m', '', trim($string));
  }

  private function normalize_slug(string $slug): string {
    $slug = trim($slug);
    $slug = preg_replace('/^\/+/', '', $slug);
    return (string)$slug;
  }
}
