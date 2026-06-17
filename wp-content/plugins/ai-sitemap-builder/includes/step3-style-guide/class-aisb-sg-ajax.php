<?php

if (!defined('ABSPATH')) exit;

/**
 * AJAX-handlers voor stijlgidsdata: ophalen, bewaren en AI-generatie.
 */
class AISB_SG_Ajax {

  public function ajax_get_style_guide(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    $guide = get_post_meta($project_id, 'aisb_style_guide', true);
    $data  = $guide ? json_decode((string)$guide, true) : null;
    // Ensure we always return a JSON object {}, never a JSON array []
    if (!is_array($data) || array_keys($data) === range(0, count($data) - 1)) {
      $data = [];
    }
    wp_send_json_success(['style_guide' => (object) $data]);
  }

  public function ajax_save_style_guide(): void {
    $this->require_login();
    $this->check_nonce();

    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    $raw  = isset($_POST['style_guide_json']) ? wp_unslash($_POST['style_guide_json']) : '';
    $data = json_decode($raw, true);

    if (!is_array($data)) wp_send_json_error(['message' => 'Invalid style_guide_json'], 400);

    update_post_meta($project_id, 'aisb_style_guide', wp_json_encode($data, JSON_UNESCAPED_SLASHES));
    wp_send_json_success(['ok' => 1]);
  }

  /**
   * AJAX: Genereer style guide — ontvangt kleuren van JS, vraagt OpenAI om font-pairings.
   */
  public function ajax_generate_style_guide(): void {
    $this->require_login();
    $this->check_nonce();

    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    $colours_raw = isset($_POST['colours']) ? wp_unslash($_POST['colours']) : '[]';
    $colours = json_decode($colours_raw, true);
    if (!is_array($colours) || empty($colours)) {
      wp_send_json_error(['message' => 'No colours supplied'], 400);
    }

    $colour_list = [];
    foreach ($colours as $c) {
      $hex = isset($c['hex']) ? preg_replace('/[^#0-9a-fA-F]/', '', $c['hex']) : '';
      if ($hex) $colour_list[] = $hex;
    }

    if (empty($colour_list)) {
      wp_send_json_error(['message' => 'No valid hex colours'], 400);
    }

    $settings = get_option('aisb_settings', []);
    if (empty($settings['api_key'])) {
      wp_send_json_error(['message' => 'OpenAI API key not configured. Go to Settings.'], 400);
    }

    $prompt = "I have the following brand colours: " . implode(', ', $colour_list) . "\n\n"
      . "Suggest a complementary Google Fonts pairing (one heading font, one body font) that matches these colours' mood.\n"
      . "Also return a type scale with 5 levels: H1, H2, H3, Body, Small. Include fontSize in px and lineHeight for every level.\n\n"
      . "Return ONLY valid JSON in this exact format:\n"
      . '{"heading_font":"Font Name","body_font":"Font Name","type_scale":['
      . '{"label":"H1","cls":"h1","fontFamily":"HEADING_FONT","fontSize":"64px","lineHeight":"1.05","sample":"Heading One"},'
      . '{"label":"H2","cls":"h2","fontFamily":"HEADING_FONT","fontSize":"48px","lineHeight":"1.1","sample":"Heading Two"},'
      . '{"label":"H3","cls":"h3","fontFamily":"HEADING_FONT","fontSize":"36px","lineHeight":"1.15","sample":"Heading Three"},'
      . '{"label":"Body","cls":"body","fontFamily":"BODY_FONT","fontSize":"18px","lineHeight":"1.6","sample":"The quick brown fox jumps over the lazy dog."},'
      . '{"label":"Small","cls":"small","fontFamily":"BODY_FONT","fontSize":"14px","lineHeight":"1.5","sample":"Fine print and captions"}'
      . "]}";

    $system = "You are a brand typography expert. Return ONLY valid JSON, no explanation, no markdown fences.";

    $openai = new AISB_OpenAI();
    $result = $openai->call_openai_chat_completions($prompt, $settings, $system);

    if (is_wp_error($result)) {
      wp_send_json_error(['message' => $result->get_error_message()]);
    }

    $fonts = json_decode($result, true);
    if (!is_array($fonts) || empty($fonts['heading_font'])) {
      wp_send_json_error(['message' => 'Invalid AI response — could not parse font suggestion.']);
    }

    wp_send_json_success([
      'fonts'   => $fonts,
      'colours' => $colours,
    ]);
  }

  /**
   * AJAX: Auto-generate font pairing using AI + validate via Google Fonts API.
   * Google Fonts API key lives in wp-config.php — never exposed to browser.
   */
  public function ajax_auto_fonts(): void {
    $this->require_login();
    $this->check_nonce();

    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    $settings = get_option('aisb_settings', []);
    if (empty($settings['api_key'])) {
      wp_send_json_error(['message' => 'OpenAI API key not configured.'], 400);
    }

    $colours_raw = isset($_POST['colours']) ? wp_unslash($_POST['colours']) : '[]';
    $colours = json_decode($colours_raw, true);
    $colour_list = [];
    if (is_array($colours)) {
      foreach ($colours as $c) {
        $hex = isset($c['hex']) ? preg_replace('/[^#0-9a-fA-F]/', '', $c['hex']) : '';
        if ($hex) $colour_list[] = $hex;
      }
    }

    $site_name = get_the_title($project_id) ?: get_bloginfo('name');

    $available_fonts = [];
    if (defined('AISB_GOOGLE_FONTS_KEY') && AISB_GOOGLE_FONTS_KEY) {
      $gf_url = add_query_arg([
        'key'  => AISB_GOOGLE_FONTS_KEY,
        'sort' => 'popularity',
      ], 'https://www.googleapis.com/webfonts/v1/webfonts');
      $gf_response = wp_remote_get($gf_url, ['timeout' => 10]);
      if (!is_wp_error($gf_response) && wp_remote_retrieve_response_code($gf_response) === 200) {
        $gf_body = json_decode(wp_remote_retrieve_body($gf_response), true);
        if (!empty($gf_body['items'])) {
          $items = array_slice($gf_body['items'], 0, 200);
          foreach ($items as $f) {
            $available_fonts[] = $f['family'];
          }
        }
      }
    }

    $font_list_hint = '';
    if ($available_fonts) {
      $font_list_hint = "\n\nYou MUST choose ONLY from these Google Fonts (pick two DIFFERENT fonts — one for headings, one for body):\n" . implode(', ', $available_fonts);
    } else {
      $font_list_hint = "\n\nChoose from popular Google Fonts such as: Montserrat, Playfair Display, Raleway, Lora, Poppins, Merriweather, Oswald, Source Sans 3, Nunito, Roboto Slab, Inter, Work Sans, Fira Sans, Libre Baskerville, DM Sans, Josefin Sans, Rubik, Bitter, Karla, Mulish, Cabin, Archivo, Crimson Text, PT Serif, Quicksand, Space Grotesk, Barlow, Cormorant Garamond, Outfit, Sora.";
    }

    $prompt = "Website name: \"{$site_name}\""
      . ($colour_list ? "\nBrand colours: " . implode(', ', $colour_list) : '')
      . "\n\nPick the best Google Fonts pairing (one heading font, one body font) that matches this brand."
      . " The heading font and body font should be DIFFERENT from each other."
      . "\n\nAlso pick TWO alternating section background colours for the website layout:"
      . " - section_bg_1: the main/lighter background (e.g. white, very light tint)"
      . " - section_bg_2: the alternating background (a noticeably different but still light tint that complements the brand)"
      . " These two colours MUST be visually distinct from each other — the user must clearly see the alternation."
      . " They can be e.g. white + light blue, cream + soft lavender, off-white + pale brand tint, etc."
      . " Do NOT use plain grey. Make it match the brand feel."
      . " Return them as hex colour values."
      . $font_list_hint
      . "\n\nReturn ONLY valid JSON. Replace every placeholder with the actual value you chose. Include fontSize in px and lineHeight for every type_scale item:"
      . "\n" . '{"heading_font":"<actual heading font name>","body_font":"<actual body font name>","section_bg_1":"<hex>","section_bg_2":"<hex>","type_scale":['
      . '{"label":"H1","cls":"h1","fontFamily":"<actual heading font name>","fontSize":"64px","lineHeight":"1.05","sample":"Heading One"},'
      . '{"label":"H2","cls":"h2","fontFamily":"<actual heading font name>","fontSize":"48px","lineHeight":"1.1","sample":"Heading Two"},'
      . '{"label":"H3","cls":"h3","fontFamily":"<actual heading font name>","fontSize":"36px","lineHeight":"1.15","sample":"Heading Three"},'
      . '{"label":"Body","cls":"body","fontFamily":"<actual body font name>","fontSize":"18px","lineHeight":"1.6","sample":"The quick brown fox jumps over the lazy dog."},'
      . '{"label":"Small","cls":"small","fontFamily":"<actual body font name>","fontSize":"14px","lineHeight":"1.5","sample":"Fine print and captions"}'
      . ']}';

    $openai = new AISB_OpenAI();
    $result = $openai->call_openai_chat_completions($prompt, $settings, 'You are a brand typography and colour expert. Return ONLY valid JSON, no explanation, no markdown fences. Every fontFamily value must be a real Google Font name, never a placeholder. section_bg_1 and section_bg_2 must be valid hex colours that are visually distinct.');

    if (is_wp_error($result)) {
      wp_send_json_error(['message' => $result->get_error_message()]);
    }

    $fonts = json_decode($result, true);
    if (!is_array($fonts) || empty($fonts['heading_font'])) {
      wp_send_json_error(['message' => 'Invalid AI response.']);
    }

    // Safety net: replace any leftover placeholders in type_scale with actual font names
    $heading = $fonts['heading_font'];
    $body    = $fonts['body_font'] ?: $heading;
    if (!empty($fonts['type_scale']) && is_array($fonts['type_scale'])) {
      foreach ($fonts['type_scale'] as &$item) {
        if (!isset($item['fontFamily'])) continue;
        $ff = $item['fontFamily'];
        if (in_array($ff, ['HEADING_FONT', '<actual heading font name>', 'heading_font', 'Font Name', ''], true)) {
          $item['fontFamily'] = $heading;
        } elseif (in_array($ff, ['BODY_FONT', '<actual body font name>', 'body_font', ''], true)) {
          $item['fontFamily'] = $body;
        }
      }
      unset($item);
    }

    wp_send_json_success([
      'fonts'   => $fonts,
      'colours' => $colours ?: [],
    ]);
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
