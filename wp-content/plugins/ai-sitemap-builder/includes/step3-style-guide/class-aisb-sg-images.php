<?php

if (!defined('ABSPATH')) exit;

/**
 * AJAX-handlers voor afbeeldingen: Unsplash-zoekresultaten en uploads.
 */
class AISB_SG_Images {

  /**
   * AJAX: Fetch Unsplash images based on the project/website name.
   * The API key lives only in wp-config.php and is never sent to the browser.
   */
  public function ajax_get_unsplash_images(): void {
    $this->require_login();
    $this->check_nonce();

    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $this->assert_project_ownership($project_id);

    if (!defined('AISB_UNSPLASH_KEY') || !AISB_UNSPLASH_KEY) {
      wp_send_json_error(['message' => 'Unsplash API key not configured (AISB_UNSPLASH_KEY missing from wp-config.php).'], 400);
    }

    $site_name = get_the_title($project_id) ?: get_bloginfo('name');
    $brief     = (string) get_post_meta($project_id, 'aisb_project_brief', true);
    $settings  = get_option('aisb_settings', []);

    $context_for_keyword = $brief ?: $site_name;
    $keyword = 'business'; // safe fallback

    if (!empty($settings['api_key'])) {
      $openai = new AISB_OpenAI();
      $prompt = "Website brief: \"{$context_for_keyword}\"\nYour task is to generate a highly relevant search phrase (1 to 3 words max) for Unsplash to find stock photography for this website. Examples: \"hearing clinic\" → \"hearing test\", \"car mechanic\" → \"auto repair\", \"bakery in Amsterdam\" → \"bakery shop\", \"law firm\" → \"lawyer office\". Return ONLY the search phrase in English, nothing else.";
      $result = $openai->call_openai_chat_completions($prompt, $settings, 'You are a stock photo keyword extractor. Return ONLY 1 to 3 english words. Never return a company name, person name, or URL.');
      if (!is_wp_error($result)) {
        $kw = strtolower(trim(wp_strip_all_tags($result)));
        $kw = str_replace(array('.', ',', '"', '\'', ':', ';', '{', '}'), '', $kw);
        if ($kw && strlen($kw) < 60) {
          $keyword = urlencode($kw);
        }
      }
    } else {
      $source = $brief ?: $site_name;
      $stop_words = ['agency', 'studio', 'digital', 'creative', 'media', 'group',
                     'company', 'services', 'solutions', 'consulting', 'co', 'inc',
                     'ltd', 'bv', 'de', 'het', 'en', 'van', 'voor', 'the', 'and',
                     'for', 'we', 'our', 'your', 'with', 'are', 'is', 'a', 'an',
                     'in', 'at', 'of', 'on', 'to', 'that', 'this', 'you', 'all'];
      $words = preg_split('/[\s\-_&,\.]+/', strtolower(trim($source)));
      foreach ($words as $w) {
        $w = preg_replace('/[^a-z]/i', '', $w);
        if ($w && !in_array($w, $stop_words, true) && strlen($w) > 3) {
          $keyword = $w;
          break;
        }
      }
    }

    $total_needed = isset($_POST['total_needed']) ? max(1, (int)$_POST['total_needed']) : 30;
    $total_needed = min($total_needed, 150);

    $images = [];
    $pages_needed = (int) ceil($total_needed / 30);
    for ($page = 1; $page <= $pages_needed; $page++) {
      $per_page = min(30, $total_needed - count($images));
      $api_url  = add_query_arg([
        'query'       => $keyword,
        'per_page'    => $per_page,
        'page'        => $page,
        'orientation' => 'landscape',
      ], 'https://api.unsplash.com/search/photos');

      $response = wp_remote_get($api_url, [
        'headers' => [
          'Authorization'  => 'Client-ID ' . AISB_UNSPLASH_KEY,
          'Accept-Version' => 'v1',
        ],
        'timeout' => 15,
      ]);

      if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Unsplash request failed: ' . $response->get_error_message()]);
      }
      $code = wp_remote_retrieve_response_code($response);
      if ($code !== 200) {
        wp_send_json_error(['message' => 'Unsplash returned HTTP ' . $code]);
      }

      $body = json_decode(wp_remote_retrieve_body($response), true);
      if (!is_array($body) || empty($body['results'])) break;

      foreach ($body['results'] as $photo) {
        $images[] = [
          'thumb'        => $photo['urls']['small']   ?? '',
          'full'         => $photo['urls']['regular'] ?? '',
          'alt'          => $photo['alt_description'] ?? $keyword,
          'photographer' => $photo['user']['name']    ?? '',
          'link'         => $photo['links']['html']   ?? '',
        ];
        if (count($images) >= $total_needed) break;
      }
      if (count($images) >= $total_needed) break;
    }

    wp_send_json_success(['images' => $images, 'keyword' => urldecode((string)$keyword)]);
  }

  /**
   * AJAX: Search similar Unsplash images for a given keyword.
   * Used when the user clicks an image to swap it.
   */
  public function ajax_search_similar_images(): void {
    $this->require_login();
    $this->check_nonce();

    if (!defined('AISB_UNSPLASH_KEY') || !AISB_UNSPLASH_KEY) {
      wp_send_json_error(['message' => 'Unsplash API key not configured.'], 400);
    }

    $keyword = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
    if (!$keyword) {
      wp_send_json_error(['message' => 'No keyword supplied.'], 400);
    }

    $page     = isset($_POST['page'])     ? max(1, (int)$_POST['page'])            : 1;
    $per_page = isset($_POST['per_page']) ? max(1, min(30, (int)$_POST['per_page'])) : 12;

    $api_url = add_query_arg([
      'query'       => $keyword,
      'per_page'    => $per_page,
      'page'        => $page,
      'orientation' => 'landscape',
    ], 'https://api.unsplash.com/search/photos');

    $response = wp_remote_get($api_url, [
      'headers' => [
        'Authorization'  => 'Client-ID ' . AISB_UNSPLASH_KEY,
        'Accept-Version' => 'v1',
      ],
      'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
      wp_send_json_error(['message' => 'Unsplash request failed.']);
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
      wp_send_json_error(['message' => 'Unsplash returned HTTP ' . wp_remote_retrieve_response_code($response)]);
    }

    $body   = json_decode(wp_remote_retrieve_body($response), true);
    $images = [];
    if (!empty($body['results'])) {
      foreach ($body['results'] as $photo) {
        $images[] = [
          'thumb'        => $photo['urls']['small']   ?? '',
          'full'         => $photo['urls']['regular'] ?? '',
          'alt'          => $photo['alt_description'] ?? $keyword,
          'photographer' => $photo['user']['name']    ?? '',
          'link'         => $photo['links']['html']   ?? '',
        ];
      }
    }

    $total_pages = isset($body['total_pages']) ? (int)$body['total_pages'] : 1;
    wp_send_json_success(['images' => $images, 'page' => $page, 'total_pages' => $total_pages]);
  }

  /**
   * AJAX: Upload images to the WP Media Library.
   * Accepts multipart file uploads, returns image objects.
   */
  public function ajax_upload_images(): void {
    $this->require_login();
    $nonce   = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $ok_sg   = $nonce && wp_verify_nonce($nonce, 'aisb_sg_nonce');
    $ok_core = $nonce && wp_verify_nonce($nonce, 'aisb_nonce_action');
    if (!$ok_sg && !$ok_core) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    if (empty($_FILES['images'])) {
      wp_send_json_error(['message' => 'No files uploaded.'], 400);
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $files   = $_FILES['images'];
    $results = [];
    $count   = is_array($files['name']) ? count($files['name']) : 1;

    for ($i = 0; $i < $count; $i++) {
      $single = [
        'name'     => is_array($files['name'])     ? $files['name'][$i]     : $files['name'],
        'type'     => is_array($files['type'])     ? $files['type'][$i]     : $files['type'],
        'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
        'error'    => is_array($files['error'])    ? $files['error'][$i]    : $files['error'],
        'size'     => is_array($files['size'])     ? $files['size'][$i]     : $files['size'],
      ];

      $check = wp_check_filetype($single['name']);
      if (empty($check['type']) || strpos($check['type'], 'image/') !== 0) {
        continue;
      }

      $_FILES['aisb_upload'] = $single;
      $attachment_id = media_handle_upload('aisb_upload', 0);
      if (is_wp_error($attachment_id)) {
        continue;
      }

      $thumb = wp_get_attachment_image_url($attachment_id, 'medium') ?: '';
      $full  = wp_get_attachment_image_url($attachment_id, 'large')  ?: wp_get_attachment_url($attachment_id);
      $alt   = get_post_meta($attachment_id, '_wp_attachment_image_alt', true) ?: '';

      $results[] = [
        'thumb'         => $thumb,
        'full'          => $full,
        'alt'           => $alt,
        'photographer'  => 'Uploaded',
        'link'          => '',
        'uploaded'      => true,
        'attachment_id' => $attachment_id,
      ];
    }

    if (empty($results)) {
      wp_send_json_error(['message' => 'No valid images could be uploaded.'], 400);
    }

    wp_send_json_success(['images' => $results]);
  }

  /**
   * AJAX: Upload logo to WP Media Library. Returns the attachment URL.
   */
  public function ajax_upload_logo(): void {
    $this->require_login();
    $this->check_nonce();

    if (empty($_FILES['logo'])) {
      wp_send_json_error(['message' => 'No file uploaded.'], 400);
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $file  = $_FILES['logo'];
    $check = wp_check_filetype($file['name']);
    if (empty($check['type']) || strpos($check['type'], 'image/') !== 0) {
      wp_send_json_error(['message' => 'Invalid file type — only images allowed.'], 400);
    }

    $_FILES['aisb_logo'] = $file;
    $attachment_id = media_handle_upload('aisb_logo', 0);
    if (is_wp_error($attachment_id)) {
      wp_send_json_error(['message' => $attachment_id->get_error_message()], 500);
    }

    $url = wp_get_attachment_image_url($attachment_id, 'full') ?: wp_get_attachment_url($attachment_id);
    wp_send_json_success(['url' => $url, 'attachment_id' => $attachment_id]);
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
