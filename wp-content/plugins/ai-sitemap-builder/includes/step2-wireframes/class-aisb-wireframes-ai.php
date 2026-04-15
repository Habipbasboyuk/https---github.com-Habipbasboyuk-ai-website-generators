<?php

if (!defined('ABSPATH')) exit;

/**
 * AI tekst generatie voor Bricks templates.
 * Leest placeholder teksten uit Bricks secties, stuurt ze naar OpenAI,
 * en slaat de gegenereerde tekst op als ai_wireframe posts.
 */
class AISB_Wireframes_AI {

  /**
   * Vervangt placeholder teksten in Bricks secties met AI-gegenereerde copy.
   * Maakt voor elke sectie een ai_wireframe post aan, originelen blijven onaangetast.
   */
  public function populate_bricks_content_with_ai(array $model, int $project_id, int $sitemap_version_id, string $page_slug): array {
    // Project brief en sitemap ophalen voor context aan OpenAI
    $brief = (string) get_post_meta($project_id, 'aisb_project_brief', true);
    $sitemap_json = get_post_meta($sitemap_version_id, 'aisb_sitemap_json', true);
    $sitemap_data = json_decode((string)$sitemap_json, true) ?: [];

    $sections_with_id = array_filter($model['sections'] ?? [], fn($s) => !empty($s['bricks_template_id']));
    error_log('[AISB] populate_bricks_content_with_ai START: page=' . $page_slug . ' total_sections=' . count($model['sections'] ?? []) . ' sections_with_bricks_id=' . count($sections_with_id));
    
    // 1. Pagina-info zoeken in de sitemap (titel, secties)
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
    $page_purpose = $page_info['page_purpose'] ?? '';
    $page_type = $page_info['page_type'] ?? '';

    // Sectie-context uit de sitemap ophalen (purpose + key_content per sectie)
    $section_hints = [];
    if (!empty($page_info['sections']) && is_array($page_info['sections'])) {
        foreach ($page_info['sections'] as $s) {
            $name = $s['section_name'] ?? $s['section_type'] ?? '';
            $purpose = $s['purpose'] ?? '';
            if ($name && $purpose) {
                $section_hints[] = "- {$name}: {$purpose}";
            }
        }
    }

    // Bricks settings-keys die tekst bevatten
    $text_keys = ['text', 'title', 'subtitle', 'description', 'heading', 'content', 'label'];
    
    $to_translate = [];         // Teksten die naar OpenAI gestuurd worden
    $raw_bricks_data_maps = []; // Originele Bricks element-data per sectie-index

    // 2. Alle tekst ophalen uit de gekozen Bricks templates
    foreach ($model['sections'] as $idx => $sec) {
        if (empty($sec['bricks_template_id'])) {
            error_log('[AISB] Section ' . $idx . ' (type=' . ($sec['type'] ?? '?') . ') has no bricks_template_id — skipped');
            continue;
        }
        
        // Bricks element-data ophalen — Bricks slaat dit op onder verschillende meta keys
        $post_id = (int) $sec['bricks_template_id'];
        $bricks_data = get_post_meta($post_id, '_bricks_page_content_2', true);
        if (!is_array($bricks_data)) {
            $bricks_data = get_post_meta($post_id, '_bricks_data', true);
        }
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

    // 3. Teksten naar OpenAI sturen voor professionele copy
    $prompt = "You are a copywriter. Replace the placeholder texts in the following JSON with professional copy.\n\n";
    $prompt .= "Project: {$brief}\n";
    $prompt .= "Page: {$page_title}";
    if ($page_type) $prompt .= " (type: {$page_type})";
    $prompt .= "\n";
    if ($page_purpose) {
        $prompt .= "Page purpose: {$page_purpose}\n";
    }
    if (!empty($section_hints)) {
        $prompt .= "Section context:\n" . implode("\n", $section_hints) . "\n";
    }
    $prompt .= "\nIMPORTANT: Write copy that is specific to this page. Do NOT use generic welcome/landing page text unless this is actually the Home page.\n";
    $prompt .= "Return ONLY the JSON with updated 'settings' values.";
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

    // 4. AI-gegenereerde tekst samenvoegen met Bricks elementen en opslaan als ai_wireframe post
    foreach ($translated as $sec_key => $data) {
        $idx = (int) str_replace('section_', '', $sec_key);
        if (!isset($raw_bricks_data_maps[$idx])) continue;
        $original_id = (int)($model['sections'][$idx]['bricks_template_id'] ?? 0);
        $cloned_data = $raw_bricks_data_maps[$idx]; // Kopie van originele elementen

        // AI-tekst invoegen in de gekopieerde elementen (oorspronkelijke data blijft intact)
        foreach ($cloned_data as &$node) {
            $nid = $node['id'];
            if (isset($data['modules'][$nid]['settings'])) {
                foreach ($data['modules'][$nid]['settings'] as $key => $new_text) {
                    $node['settings'][$key] = $new_text;
                }
            }
        }
        unset($node);

        // Nieuwe ai_wireframe post aanmaken met de AI-gegenereerde Bricks elementen
        $new_post_id = wp_insert_post([
            'post_title'  => "[AI] " . $page_title . " - Section " . $idx,
            'post_type'   => 'ai_wireframe',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($new_post_id) || !$new_post_id) {
            error_log('[AISB] wp_insert_post (ai_wireframe) FAILED for section ' . $idx . ': ' . (is_wp_error($new_post_id) ? $new_post_id->get_error_message() : 'returned 0'));
        } else {
            // Bricks elementen opslaan in de post meta
            update_post_meta($new_post_id, '_bricks_page_content_2', $cloned_data);
            // Metadata voor groepering en herleidbaarheid
            update_post_meta($new_post_id, '_aisb_source_template_id', $original_id); // Origineel Bricks template
            update_post_meta($new_post_id, '_aisb_project_id', $project_id);          // Bij welk project dit hoort
            update_post_meta($new_post_id, '_aisb_page_slug', $page_slug);            // Voor welke pagina

            // Koppel de ai_wireframe post aan de sectie in het wireframe model
            $model['sections'][$idx]['ai_wireframe_id'] = $new_post_id;
            error_log('[AISB] Created ai_wireframe post ID=' . $new_post_id . ' for section ' . $idx . ' (' . count($cloned_data) . ' nodes)');
        }
    }

    return $model;
  }

  // Markdown code-blokken verwijderen uit het OpenAI antwoord
  public function clean_json_response($string) {
    return preg_replace('/^```json|```$/m', '', trim($string));
  }

  // Slug normaliseren (voorloop-slashes verwijderen)
  private function normalize_slug(string $slug): string {
    $slug = trim($slug);
    $slug = preg_replace('/^\/+/', '', $slug);
    return (string)$slug;
  }
}
