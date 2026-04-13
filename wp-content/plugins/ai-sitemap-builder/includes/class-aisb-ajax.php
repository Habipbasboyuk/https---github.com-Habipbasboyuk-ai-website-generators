<?php

if (!defined('ABSPATH')) exit;

class AISB_Ajax {

  /**
   * Lazy helpers to avoid tight coupling and repeated instantiation.
   */
  private function prompts(): AISB_Prompts {
    static $p = null;
    if (!$p) { $p = new AISB_Prompts(); }
    return $p;
  }

  private function enforcer(): AISB_Enforcer {
    static $e = null;
    if (!$e) { $e = new AISB_Enforcer(); }
    return $e;
  }

  // Backwards-compatible wrappers (older code called these on AISB_Ajax)
  private function system_prompt(): string {
    return $this->prompts()->system_prompt();
  }

  private function single_page_prompt($title, $desc, $parent_slug, $site_context_json): string {
    return $this->prompts()->single_page_prompt($title, $desc, $parent_slug, $site_context_json);
  }

  private function demo_response(): array {
    return $this->prompts()->demo_response();
  }

  private function build_page_stub($title, $desc, $parent_slug): array {
    return $this->enforcer()->build_page_stub($title, $desc, $parent_slug);
  }

  private function enforce_page_sections($page, $is_home = false): array {
    return $this->enforcer()->enforce_page_sections($page, $is_home);
  }

      public function ajax_generate() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $key = 'aisb_rl_' . md5($ip);
    $this->aisb_require_login();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $count = (int) get_transient($key);
    if ($count > 30) wp_send_json_error(['message' => 'Rate limit exceeded. Try again later.'], 429);
    set_transient($key, $count + 1, 60 * 10);
    $settings = $this->get_settings();

    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');

    $prompt = isset($_POST['prompt']) ? wp_unslash($_POST['prompt']) : '';
    $prompt = trim($prompt);

    if ($prompt === '' || strlen($prompt) < 10) wp_send_json_error(['message' => 'Please provide a more detailed brief (min 10 characters).'], 400);
    if (strlen($prompt) > 4000) wp_send_json_error(['message' => 'Prompt is too long (max 4000 characters).'], 400);
    
    $languages_raw = isset($_POST['languages']) ? wp_unslash($_POST['languages']) : '[]';
    $page_count    = isset($_POST['page_count']) ? sanitize_text_field(wp_unslash($_POST['page_count'])) : '';
    
    $languages = json_decode($languages_raw, true);
    if (!is_array($languages)) $languages = [];
    $languages = array_values(array_filter(array_map(function($x){
      return is_string($x) ? trim($x) : '';
    }, $languages), function($x){ return $x !== ''; }));
    
    $allowed_lang = ['English','French','Dutch','German'];
    $languages = array_values(array_intersect($languages, $allowed_lang));
    
    $allowed_counts = ['1','2-5','5-10','10-15','15+'];
    if (!in_array($page_count, $allowed_counts, true)) $page_count = '5-10';
    
    $this->append_debug_log([
      'event' => 'generate_start',
      'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown',
      'user_id' => get_current_user_id(),
      'demo_mode' => empty($settings['api_key']),
      'frontend_brief' => $this->redact_large_text($prompt, 12000),
      'model' => $settings['model'] ?? '',
      'endpoint' => $settings['endpoint'] ?? '',
    ]);

    if (empty($settings['api_key'])) {
      $demo = $this->prompts()->demo_structure_response();
      $demo = $this->enforcer()->enforce_structure_only($demo);
      $demo['structure_only'] = true;

      $this->append_debug_log([
        'event'          => 'generate_demo_structure',
        'frontend_brief' => $this->redact_large_text($prompt, 12000),
        'raw_ai_content' => '(demo_mode)',
        'decoded_json_ok' => true,
        'enforced_output' => $demo,
      ]);

      wp_send_json_success(['data' => $demo, 'demo' => true, 'structure_only' => true]);
    }

    $meta = "Constraints:\n"
      . "- Languages: " . (!empty($languages) ? implode(', ', $languages) : "Not specified") . "\n"
      . "- Desired number of pages: " . $page_count . "\n\n";

    $augmented_prompt = $meta . $prompt;

    $openai  = new AISB_OpenAI();
    $sys_prompt = $this->prompts()->structure_only_system_prompt();
    $result  = $openai->call_openai_chat_completions($augmented_prompt, $settings, $sys_prompt);

    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 500);

    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
      wp_send_json_error([
        'message' => 'The AI response was not valid JSON. Check your model/settings.',
        'raw'     => $result,
      ], 500);
    }

    $decoded = $this->enforcer()->enforce_structure_only($decoded);
    $decoded['structure_only'] = true;

    // Create project if needed
    if (!$project_id) {
      $title      = $decoded['website_name'] ?? 'New project';
      $project_id = $this->aisb_create_project(
        sanitize_text_field($title),
        $prompt,
        $languages,
        $page_count
      );

      if (is_wp_error($project_id)) {
        wp_send_json_error(['message' => $project_id->get_error_message()], 500);
      }
    } else {
      if (!$this->aisb_user_can_access_project($project_id)) {
        wp_send_json_error(['message' => 'Forbidden.'], 403);
      }
    }

    // Save structure-only snapshot
    $settings = $this->get_settings();
    $save = $this->aisb_create_sitemap_version($project_id, $decoded, [
      'source'      => 'ai',
      'label'       => 'Structure generated',
      'prompt'      => $augmented_prompt,
      'model'       => $settings['model'] ?? '',
      'endpoint'    => $settings['endpoint'] ?? '',
      'temperature' => 0.4,
      'status'      => 'structure_only',
    ]);

    if (is_wp_error($save)) {
      wp_send_json_error(['message' => $save->get_error_message()], 500);
    }

    wp_send_json_success([
      'project_id'     => (int)$project_id,
      'sitemap_id'     => (int)$save['sitemap_id'],
      'version'        => (int)$save['version'],
      'data'           => $decoded,
      'demo'           => false,
      'structure_only' => true,
    ]);
  }

  private function get_settings(): array {
  // Preferred: reuse AISB_Settings instance method (non-static)
  if (class_exists('AISB_Settings')) {
    $settings_obj = new AISB_Settings();
    if (method_exists($settings_obj, 'get_settings')) {
      $settings = $settings_obj->get_settings();
      return is_array($settings) ? $settings : [];
    }
  }

  // Fallback: read directly from options
  $defaults = [
    'api_key'   => '',
    'endpoint'  => 'https://api.openai.com/v1/chat/completions',
    'model'     => 'gpt-4o-mini',
    'timeout'   => 30,
  ];

  $saved = get_option(AISB_Plugin::OPT_KEY, []);
  $saved = is_array($saved) ? $saved : [];

  return array_merge($defaults, $saved);
}


    public function ajax_add_page() {
      $this->aisb_require_login();
      check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');
    
      $settings = $this->get_settings();
    
      $parent_slug = isset($_POST['parent_slug']) ? sanitize_text_field(wp_unslash($_POST['parent_slug'])) : 'home';
      $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
      $desc  = isset($_POST['desc']) ? sanitize_text_field(wp_unslash($_POST['desc'])) : '';
      $site_context = isset($_POST['site_context']) ? wp_unslash($_POST['site_context']) : '';
    
      if ($title === '' || strlen($title) < 2) wp_send_json_error(['message' => 'Please provide a page title.'], 400);
      if (strlen($desc) < 3) wp_send_json_error(['message' => 'Please provide a short description.'], 400);
    
      // ✅ Log start
      $this->append_debug_log([
        'event' => 'add_page_start',
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown',
        'user_id' => get_current_user_id(),
        'demo_mode' => empty($settings['api_key']),
        'parent_slug' => $parent_slug,
        'title' => $title,
        'desc' => $desc,
        'site_context' => $this->redact_large_text(is_string($site_context) ? $site_context : '', 12000),
        'model' => $settings['model'] ?? '',
        'endpoint' => $settings['endpoint'] ?? '',
      ]);
    
      if (empty($settings['api_key'])) {
        $page = $this->build_page_stub($title, $desc, $parent_slug);
    
        $this->append_debug_log([
          'event' => 'add_page_demo',
          'parent_slug' => $parent_slug,
          'title' => $title,
          'desc' => $desc,
          'raw_ai_content' => '(demo_mode)',
          'enforced_page' => $page,
        ]);
    
        wp_send_json_success(['page' => $page, 'demo' => true]);
      }
    
      $page_prompt = $this->single_page_prompt($title, $desc, $parent_slug, $site_context);
    
      $t0 = microtime(true);
      $system_prompt = $this->system_prompt();
    
      $openai = new AISB_OpenAI();
      $result = $openai->call_openai_chat_completions($page_prompt, $settings);

      $elapsed = round(microtime(true) - $t0, 3);
    
      if (is_wp_error($result)) {
        $this->append_debug_log([
          'event' => 'add_page_error',
          'system_prompt' => $this->redact_large_text($system_prompt, 12000),
          'user_prompt' => $this->redact_large_text($page_prompt, 25000),
          'elapsed_s' => $elapsed,
          'error' => $result->get_error_message(),
        ]);
        wp_send_json_error(['message' => $result->get_error_message()], 500);
      }
    
      $raw_ai = $result;
      $decoded = json_decode($raw_ai, true);
    
      if (!is_array($decoded)) {
        $this->append_debug_log([
          'event' => 'add_page_decode_failed',
          'system_prompt' => $this->redact_large_text($system_prompt, 12000),
          'user_prompt' => $this->redact_large_text($page_prompt, 25000),
          'elapsed_s' => $elapsed,
          'raw_ai_content' => $this->redact_large_text($raw_ai, 25000),
          'decoded_json_ok' => false,
          'json_last_error' => function_exists('json_last_error_msg') ? json_last_error_msg() : json_last_error(),
        ]);
    
        wp_send_json_error([
          'message' => 'The AI response was not valid JSON for add-page.',
          'raw' => $raw_ai
        ], 500);
      }
    
      $enforced_page = $this->enforce_page_sections($decoded);
    
      $this->append_debug_log([
        'event' => 'add_page_success',
        'system_prompt' => $this->redact_large_text($system_prompt, 12000),
        'user_prompt' => $this->redact_large_text($page_prompt, 25000),
        'elapsed_s' => $elapsed,
        'raw_ai_content' => $this->redact_large_text($raw_ai, 25000),
        'decoded_json_ok' => true,
        'decoded_json' => $decoded,
        'enforced_page' => $enforced_page,
      ]);
    
      wp_send_json_success(['page' => $enforced_page, 'demo' => false]);
    }

  /**
   * Step 2: generate sections for an already-approved page structure.
   */
  public function ajax_fill_sections() {
    $this->aisb_require_login();
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');

    $settings   = $this->get_settings();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

    if (!$project_id) wp_send_json_error(['message' => 'Missing project_id.'], 400);
    if (!$this->aisb_user_can_access_project($project_id)) wp_send_json_error(['message' => 'Forbidden.'], 403);

    $json_raw = isset($_POST['sitemap_json']) ? wp_unslash($_POST['sitemap_json']) : '';
    $structure = json_decode($json_raw, true);
    if (!is_array($structure)) wp_send_json_error(['message' => 'Invalid sitemap_json.'], 400);

    // Strip the structure_only flag before passing to AI / saving
    unset($structure['structure_only']);

    $this->append_debug_log([
      'event'      => 'fill_sections_start',
      'ip'         => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown',
      'user_id'    => get_current_user_id(),
      'project_id' => $project_id,
      'demo_mode'  => empty($settings['api_key']),
      'page_count' => count($structure['sitemap'] ?? []),
    ]);

    if (empty($settings['api_key'])) {
      // Demo mode: run full enforcement to add default sections to every page
      $full = $this->enforcer()->enforce_rules_on_data($structure);

      $this->append_debug_log([
        'event'          => 'fill_sections_demo',
        'raw_ai_content' => '(demo_mode)',
        'enforced_output' => $full,
      ]);

      $save = $this->aisb_create_sitemap_version($project_id, $full, [
        'source' => 'ai',
        'label'  => 'Sections generated',
        'status' => 'generated',
      ]);

      if (is_wp_error($save)) {
        wp_send_json_error(['message' => $save->get_error_message()], 500);
      }

      wp_send_json_success([
        'project_id' => (int)$project_id,
        'sitemap_id' => (int)$save['sitemap_id'],
        'version'    => (int)$save['version'],
        'data'       => $full,
        'demo'       => true,
      ]);
    }

    // Build user prompt with existing structure
    $structure_json  = wp_json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $user_prompt     = $this->prompts()->fill_sections_user_prompt($structure_json);
    $system_prompt   = $this->prompts()->system_prompt(); // full prompt knows the section schema

    $openai = new AISB_OpenAI();
    $result = $openai->call_openai_chat_completions($user_prompt, $settings, $system_prompt);

    if (is_wp_error($result)) {
      wp_send_json_error(['message' => $result->get_error_message()], 500);
    }

    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
      wp_send_json_error([
        'message' => 'The AI response was not valid JSON for fill-sections.',
        'raw'     => $result,
      ], 500);
    }

    // Strip structure_only flag if AI echoed it back
    unset($decoded['structure_only']);

    $decoded = $this->enforcer()->enforce_rules_on_data($decoded);

    $save = $this->aisb_create_sitemap_version($project_id, $decoded, [
      'source'      => 'ai',
      'label'       => 'Sections generated',
      'model'       => $settings['model'] ?? '',
      'endpoint'    => $settings['endpoint'] ?? '',
      'temperature' => 0.4,
      'status'      => 'generated',
    ]);

    if (is_wp_error($save)) {
      wp_send_json_error(['message' => $save->get_error_message()], 500);
    }

    wp_send_json_success([
      'project_id' => (int)$project_id,
      'sitemap_id' => (int)$save['sitemap_id'],
      'version'    => (int)$save['version'],
      'data'       => $decoded,
      'demo'       => false,
    ]);
  }

      public function ajax_create_project() {
    $this->aisb_require_login();
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'New project';
    $brief = isset($_POST['brief']) ? wp_unslash($_POST['brief']) : '';
    $page_count = isset($_POST['page_count']) ? sanitize_text_field(wp_unslash($_POST['page_count'])) : '5-10';

    $languages_raw = isset($_POST['languages']) ? wp_unslash($_POST['languages']) : '[]';
    $languages = json_decode($languages_raw, true);
    if (!is_array($languages)) $languages = [];

    $allowed_counts = ['1','2-5','5-10','10-15','15+'];
    if (!in_array($page_count, $allowed_counts, true)) $page_count = '5-10';

    $project_id = $this->aisb_create_project($title, $brief, $languages, $page_count);

    if (is_wp_error($project_id)) {
      wp_send_json_error(['message' => $project_id->get_error_message()], 500);
    }

    wp_send_json_success([
      'project_id' => (int)$project_id,
    ]);
  }

  public function ajax_save_sitemap_version() {
    $this->aisb_require_login();
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');

    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    if (!$project_id) wp_send_json_error(['message' => 'Missing project_id.'], 400);
    if (!$this->aisb_user_can_access_project($project_id)) wp_send_json_error(['message' => 'Forbidden.'], 403);

    $json_raw = isset($_POST['sitemap_json']) ? wp_unslash($_POST['sitemap_json']) : '';
    $decoded = json_decode($json_raw, true);
    if (!is_array($decoded)) wp_send_json_error(['message' => 'Invalid sitemap_json (must be JSON object).'], 400);

    // Enforce your rules again server-side (good)
    $decoded = $this->enforcer()->enforce_rules_on_data($decoded);

    $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : 'Manual save';
    $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'edited';

    $res = $this->aisb_create_sitemap_version($project_id, $decoded, [
      'source' => 'manual',
      'label' => $label,
      'status' => $status,
    ]);

    if (is_wp_error($res)) {
      wp_send_json_error(['message' => $res->get_error_message()], 500);
    }

    wp_send_json_success($res);
  }

  public function ajax_get_latest_sitemap() {
    $this->aisb_require_login();
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');

    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    if (!$project_id) wp_send_json_error(['message' => 'Missing project_id.'], 400);
    if (!$this->aisb_user_can_access_project($project_id)) wp_send_json_error(['message' => 'Forbidden.'], 403);

    $latest_id = (int)get_post_meta($project_id, 'aisb_latest_sitemap_id', true);
    if (!$latest_id) wp_send_json_error(['message' => 'No sitemap yet.'], 404);
    if (!$this->aisb_user_can_access_sitemap($latest_id)) wp_send_json_error(['message' => 'Forbidden.'], 403);

    $json = get_post_meta($latest_id, 'aisb_sitemap_json', true);
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) wp_send_json_error(['message' => 'Stored sitemap JSON is invalid.'], 500);

    $version = (int) get_post_meta($latest_id, 'aisb_sitemap_version', true);

    wp_send_json_success([
      'project_id' => $project_id,
      'sitemap_id' => $latest_id,
      'version' => $version,
      'data' => $decoded,
    ]);
  }

  /**
   * Fetch a specific sitemap snapshot by its post ID.
   * Used by the [my-projects] shortcode to load a historical version into the builder.
   */
  public function ajax_get_sitemap_by_id() {
    $this->aisb_require_login();
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');

    $sitemap_id = isset($_POST['sitemap_id']) ? (int)$_POST['sitemap_id'] : 0;
    if (!$sitemap_id) wp_send_json_error(['message' => 'Missing sitemap_id.'], 400);
    if (!$this->aisb_user_can_access_sitemap($sitemap_id)) wp_send_json_error(['message' => 'Forbidden.'], 403);

    $project_id = (int) get_post_meta($sitemap_id, 'aisb_project_id', true);
    $version = (int) get_post_meta($sitemap_id, 'aisb_sitemap_version', true);

    $json = get_post_meta($sitemap_id, 'aisb_sitemap_json', true);
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
      wp_send_json_error(['message' => 'Stored sitemap JSON is invalid.'], 500);
    }

    wp_send_json_success([
      'project_id' => $project_id,
      'sitemap_id' => $sitemap_id,
      'version' => $version,
      'data' => $decoded,
    ]);
  }

  public function ajax_list_projects() {
    $this->aisb_require_login();
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');

    $args = [
      'post_type' => 'aisb_project',
      'post_status' => 'publish',
      'posts_per_page' => 50,
      'orderby' => 'date',
      'order' => 'DESC',
    ];

    if (!$this->aisb_is_admin()) {
      $args['author'] = get_current_user_id();
    }

    $q = new WP_Query($args);
    $items = [];

    foreach ($q->posts as $p) {
      $items[] = [
        'id' => $p->ID,
        'title' => $p->post_title,
        'status' => (string)get_post_meta($p->ID, 'aisb_project_status', true),
        'languages' => (string)get_post_meta($p->ID, 'aisb_project_languages', true),
        'latest_sitemap_id' => (int)get_post_meta($p->ID, 'aisb_latest_sitemap_id', true),
        'latest_sitemap_version' => (int)get_post_meta($p->ID, 'aisb_latest_sitemap_version', true),
        'updated_at' => (string)get_post_meta($p->ID, 'aisb_project_updated_at', true),
      ];
    }

    wp_send_json_success(['projects' => $items]);
  }

  public function ajax_list_sitemap_versions() {
    $this->aisb_require_login();
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');

    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    if (!$project_id) wp_send_json_error(['message' => 'Missing project_id.'], 400);
    if (!$this->aisb_user_can_access_project($project_id)) wp_send_json_error(['message' => 'Forbidden.'], 403);

    $q = new WP_Query([
      'post_type' => 'aisb_sitemap',
      'post_status' => 'publish',
      'posts_per_page' => 100,
      'orderby' => 'meta_value_num',
      'order' => 'DESC',
      'meta_key' => 'aisb_sitemap_version',
      'meta_query' => [
        [
          'key' => 'aisb_project_id',
          'value' => $project_id,
          'compare' => '=',
        ]
      ],
      'fields' => 'ids',
    ]);

    $items = [];
    foreach ($q->posts as $sid) {
      $items[] = [
        'id' => (int)$sid,
        'version' => (int)get_post_meta($sid, 'aisb_sitemap_version', true),
        'label' => (string)get_post_meta($sid, 'aisb_sitemap_label', true),
        'status' => (string)get_post_meta($sid, 'aisb_sitemap_status', true),
        'created_at' => (string)get_post_meta($sid, 'aisb_sitemap_created_at', true),
        'is_current' => (int)get_post_meta($sid, 'aisb_sitemap_is_current', true),
      ];
    }

    wp_send_json_success(['versions' => $items]);
  }

  private function aisb_require_login(): void {
    if (!is_user_logged_in()) {
      wp_send_json_error([
        'message' => 'You must be logged in to use this feature.',
      ], 401);
    }
  }

    private function append_debug_log(array $entry): void {
    // Ensure we never log API keys accidentally
    if (isset($entry['api_key'])) {
      $entry['api_key'] = '[REDACTED]';
    }

    $entry['ts'] = gmdate('c');

    $log = get_option('aisb_debug_log', []);
    if (!is_array($log)) $log = [];

    // Keep log bounded (latest 50 entries)
    $log[] = $entry;
    $max = 50;
    if (count($log) > $max) {
      $log = array_slice($log, -$max);
    }

    update_option('aisb_debug_log', $log, false);
  }

  private function redact_large_text(string $text, int $maxLen = 12000): string {
    $text = (string) $text;
    if (strlen($text) <= $maxLen) return $text;
    return substr($text, 0, $maxLen) . "\n...[TRUNCATED]...";
  }




  // ---------------------------------------------------------------------------
  // Project & Sitemap persistence helpers (used by AJAX endpoints)
  // ---------------------------------------------------------------------------

  private function aisb_is_admin(): bool {
    return current_user_can('manage_options');
  }

  private function aisb_user_can_access_project(int $project_id): bool {
    $p = get_post($project_id);
    if (!$p || $p->post_type !== 'aisb_project') return false;
    if ($this->aisb_is_admin()) return true;
    return ((int)$p->post_author === (int)get_current_user_id());
  }

  private function aisb_user_can_access_sitemap(int $sitemap_id): bool {
    $p = get_post($sitemap_id);
    if (!$p || $p->post_type !== 'aisb_sitemap') return false;
    if ($this->aisb_is_admin()) return true;

    $project_id = (int) get_post_meta($sitemap_id, 'aisb_project_id', true);
    if (!$project_id) return false;
    return $this->aisb_user_can_access_project($project_id);
  }

  private function aisb_create_project(string $title, string $brief, array $languages, string $page_count) {
    $title = trim($title) !== '' ? $title : 'New project';

    $post_id = wp_insert_post([
      'post_type'   => 'aisb_project',
      'post_status' => 'publish',
      'post_title'  => wp_strip_all_tags($title),
      'post_author' => get_current_user_id(),
    ], true);

    if (is_wp_error($post_id)) return $post_id;

    update_post_meta($post_id, 'aisb_project_brief', wp_kses_post($brief));
    update_post_meta($post_id, 'aisb_project_languages', wp_json_encode(array_values($languages)));
    update_post_meta($post_id, 'aisb_project_page_count', sanitize_text_field($page_count));
    update_post_meta($post_id, 'aisb_project_status', 'draft');
    update_post_meta($post_id, 'aisb_project_updated_at', gmdate('c'));

    // Initialize versioning fields
    update_post_meta($post_id, 'aisb_latest_sitemap_id', 0);
    update_post_meta($post_id, 'aisb_latest_sitemap_version', 0);

    return (int)$post_id;
  }

  /**
   * Creates a new sitemap version post linked to a project and marks it as current.
   * Returns: ['sitemap_id' => int, 'version' => int]
   */
  private function aisb_create_sitemap_version(int $project_id, array $data, array $meta = []) {
    if (!$project_id) return new WP_Error('aisb_missing_project', 'Missing project_id.');
    if (!$this->aisb_user_can_access_project($project_id)) return new WP_Error('aisb_forbidden', 'Forbidden.');

    $latest_version = (int) get_post_meta($project_id, 'aisb_latest_sitemap_version', true);
    $version = $latest_version > 0 ? $latest_version + 1 : 1;

    $project_title = get_the_title($project_id);
    $label = isset($meta['label']) ? (string)$meta['label'] : 'Version ' . $version;

    $post_id = wp_insert_post([
      'post_type'   => 'aisb_sitemap',
      'post_status' => 'publish',
      'post_title'  => wp_strip_all_tags($project_title . ' — v' . $version),
      'post_author' => get_current_user_id(),
    ], true);

    if (is_wp_error($post_id)) return $post_id;

    // Unset previous current flag for this project
    $prev_current = (int) get_post_meta($project_id, 'aisb_latest_sitemap_id', true);
    if ($prev_current) {
      update_post_meta($prev_current, 'aisb_sitemap_is_current', 0);
    }

    // Store payload + metadata
    update_post_meta($post_id, 'aisb_project_id', $project_id);
    update_post_meta($post_id, 'aisb_sitemap_version', $version);
    update_post_meta($post_id, 'aisb_sitemap_label', sanitize_text_field($label));
    update_post_meta($post_id, 'aisb_sitemap_status', sanitize_text_field($meta['status'] ?? 'generated'));
    update_post_meta($post_id, 'aisb_sitemap_source', sanitize_text_field($meta['source'] ?? 'ai'));
    update_post_meta($post_id, 'aisb_sitemap_prompt', isset($meta['prompt']) ? wp_kses_post($meta['prompt']) : '');
    update_post_meta($post_id, 'aisb_sitemap_model', sanitize_text_field($meta['model'] ?? ''));
    update_post_meta($post_id, 'aisb_sitemap_endpoint', sanitize_text_field($meta['endpoint'] ?? ''));
    update_post_meta($post_id, 'aisb_sitemap_temperature', isset($meta['temperature']) ? (string)$meta['temperature'] : '');
    update_post_meta($post_id, 'aisb_sitemap_created_at', gmdate('c'));
    update_post_meta($post_id, 'aisb_sitemap_is_current', 1);

    // Persist JSON
    update_post_meta($post_id, 'aisb_sitemap_json', wp_json_encode($data));

    // Update project pointers
    update_post_meta($project_id, 'aisb_latest_sitemap_id', (int)$post_id);
    update_post_meta($project_id, 'aisb_latest_sitemap_version', (int)$version);
    update_post_meta($project_id, 'aisb_project_updated_at', gmdate('c'));

    return [
      'sitemap_id' => (int)$post_id,
      'version'   => (int)$version,
    ];
  }


}