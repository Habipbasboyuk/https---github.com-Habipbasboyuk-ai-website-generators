<?php

if (!defined('ABSPATH')) exit;

/**
 * Beheert de instellingenpagina van AI Sitemap Builder.
 *
 * Hier worden OpenAI-, InstaWP- en debuginstellingen geregistreerd,
 * gesanitized en gerenderd in wp-admin.
 */
class AISB_Settings {
  /**
   * Voegt de instellingenpagina toe onder WordPress Instellingen.
   */
      public function admin_menu() {
    add_options_page('AI Sitemap Builder', 'AI Sitemap Builder', 'manage_options', 'ai-sitemap-builder', [$this, 'render_settings_page']);
  }

  /**
   * Registreert alle velden en secties voor de plugininstellingen.
   */
  public function register_settings() {
    register_setting('aisb_settings_group', AISB_Plugin::OPT_KEY, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize_settings'],
      'default' => []
    ]);

    add_settings_section('aisb_main', 'OpenAI API Settings', function() {
      echo '<p>Configure your OpenAI API credentials. Default endpoint is OpenAI Chat Completions.</p>';
    }, 'ai-sitemap-builder');

    add_settings_section('aisb_instawp', 'InstaWP Publishing', function() {
      echo '<p>Used when cloning a new InstaWP site and importing the generated pages into it.</p>';
    }, 'ai-sitemap-builder');

    $openai_fields = [
      'api_key'  => ['OpenAI API Key', 'password', 'Your OpenAI API key (stored in wp_options).'],
      'endpoint' => ['Endpoint', 'text', 'Default: https://api.openai.com/v1/chat/completions'],
      'model'    => ['Model', 'text', 'Example: gpt-4o-mini, gpt-4.1-mini, etc.'],
      'timeout'  => ['Timeout (seconds)', 'number', 'Example: 30'],
    ];

    $instawp_fields = [
      'instawp_api_key' => ['InstaWP API Key', 'password', 'Bearer token used for clone requests.'],
      'instawp_template_id' => ['InstaWP Template Slug / ID', 'text', 'Prefer the template slug, for example bricks-ai-base. Legacy numeric IDs still work when mapped.'],
      'instawp_timeout' => ['InstaWP Timeout (seconds)', 'number', 'Network timeout for the clone request and remote publish calls.'],
    ];
    
    add_settings_section('aisb_debug', 'Debug log', function() {
      echo '<p>Stores the most recent requests and responses to help troubleshoot ordering and schema issues. API keys are never logged.</p>';
    }, 'ai-sitemap-builder');

    add_settings_field(
      'aisb_debug_log',
      'Recent log entries',
      function() {
        $log = get_option(AISB_Plugin::LOG_OPT_KEY, []);
        if (!is_array($log)) $log = [];

        // Toon de debuglog leesbaar als JSON.
        $txt = wp_json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        echo '<textarea class="large-text code" rows="18" readonly>' . esc_textarea($txt ?: '[]') . '</textarea>';

        // De wislink gebruikt GET met nonce, zodat er geen formulier-in-formulier ontstaat.
        $clear_url = wp_nonce_url(
          add_query_arg(['page' => 'ai-sitemap-builder', 'aisb_clear_log' => 1], admin_url('options-general.php')),
          'aisb_clear_log',
          'aisb_clear_log_nonce'
        );

        echo '<p style="margin-top:10px;">';
        echo '<a class="button button-secondary" href="' . esc_url($clear_url) . '">Clear log</a>';
        echo '</p>';
      },
      'ai-sitemap-builder',
      'aisb_debug'
    );

    foreach ($openai_fields as $key => $cfg) {
      add_settings_field(
        'aisb_' . $key,
        esc_html($cfg[0]),
        function() use ($key, $cfg) {
          $opts = get_option(AISB_Plugin::OPT_KEY, []);
          $val = isset($opts[$key]) ? $opts[$key] : '';
          $type = $cfg[1];
          $desc = $cfg[2];

          if ($key === 'timeout') $val = $val !== '' ? (int)$val : 30;

          printf(
            '<input type="%s" class="regular-text" name="%s[%s]" value="%s" />',
            esc_attr($type),
            esc_attr(AISB_Plugin::OPT_KEY),
            esc_attr($key),
            esc_attr($val)
          );
          echo '<p class="description">' . esc_html($desc) . '</p>';
        },
        'ai-sitemap-builder',
        'aisb_main'
      );
    }

    foreach ($instawp_fields as $key => $cfg) {
      add_settings_field(
        'aisb_' . $key,
        esc_html($cfg[0]),
        function() use ($key, $cfg) {
          $opts = get_option(AISB_Plugin::OPT_KEY, []);
          $val = isset($opts[$key]) ? $opts[$key] : '';
          $type = $cfg[1];
          $desc = $cfg[2];

          if ($key === 'instawp_timeout') {
            $val = $val !== '' && (int) $val >= 120 ? min(300, (int) $val) : 240;
          }

          printf(
            '<input type="%s" class="regular-text" name="%s[%s]" value="%s" />',
            esc_attr($type),
            esc_attr(AISB_Plugin::OPT_KEY),
            esc_attr($key),
            esc_attr($val)
          );
          echo '<p class="description">' . esc_html($desc) . '</p>';
        },
        'ai-sitemap-builder',
        'aisb_instawp'
      );
    }
  }

    public function sanitize_settings($input) {
    $out = [];
    $out['api_key']  = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';
    $out['endpoint'] = isset($input['endpoint']) ? esc_url_raw($input['endpoint']) : 'https://api.openai.com/v1/chat/completions';
    $out['model']    = isset($input['model']) ? sanitize_text_field($input['model']) : 'gpt-4o-mini';
    $out['timeout']  = isset($input['timeout']) ? max(5, min(120, (int)$input['timeout'])) : 30;
    $out['instawp_api_key'] = isset($input['instawp_api_key']) ? sanitize_text_field($input['instawp_api_key']) : '';
    $out['instawp_template_id'] = isset($input['instawp_template_id']) ? sanitize_text_field($input['instawp_template_id']) : '';
    $out['instawp_timeout'] = isset($input['instawp_timeout']) && (int) $input['instawp_timeout'] >= 120 ? min(300, (int) $input['instawp_timeout']) : 240;
    return $out;
  }

  public function render_settings_page() {
    if (!current_user_can('manage_options')) return; ?>
    <div class="wrap">
      <h1>AI Sitemap Builder</h1>
      <form method="post" action="options.php">
        <?php
          settings_fields('aisb_settings_group');
          do_settings_sections('ai-sitemap-builder');
          submit_button();
        ?>
      </form>

      <hr />
      <h2>Shortcode</h2>
      <p>Use this shortcode on any page:</p>
      <code>[ai_sitemap_builder]</code>
      <?php if (isset($_GET['cleared'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Debug log cleared.</p></div>
      <?php endif; ?>
    </div>
    <?php
  }

  public static function get_settings():array {
    $defaults = [
      'api_key' => '',
      'endpoint' => 'https://api.openai.com/v1/chat/completions',
      'model' => 'gpt-4o-mini',
      'timeout' => 30,
      'instawp_api_key' => '',
      'instawp_template_id' => '',
      'instawp_timeout' => 240,
    ];
    $saved = get_option(AISB_Plugin::OPT_KEY, []);
    $settings = array_merge($defaults, is_array($saved) ? $saved : []);

    $env = self::get_env_overrides();
    foreach ($env as $key => $value) {
      if ($value === '' || $value === null) continue;
      $settings[$key] = $value;
    }

    return $settings;
  }

  private static function get_env_overrides(): array {
    $api_key = self::read_env_value('INSTAWP_API_KEY');
    $template_id = self::read_env_value('INSTAWP_TEMPLATE_SLUG');
    if ($template_id === '') {
      $template_id = self::read_env_value('INSTAWP_TEMPLATE_ID');
    }
    $timeout = self::read_env_value('INSTAWP_TIMEOUT');

    return [
      'instawp_api_key' => $api_key,
      'instawp_template_id' => $template_id,
      'instawp_timeout' => $timeout !== '' ? ((int) $timeout >= 120 ? min(300, (int) $timeout) : 240) : '',
    ];
  }

  private static function read_env_value(string $key): string {
    $runtime = getenv($key);
    if (is_string($runtime) && trim($runtime) !== '') {
      return trim($runtime);
    }

    if (!empty($_ENV[$key]) && is_scalar($_ENV[$key])) {
      return trim((string) $_ENV[$key]);
    }

    if (!empty($_SERVER[$key]) && is_scalar($_SERVER[$key])) {
      return trim((string) $_SERVER[$key]);
    }

    $env = self::parse_env_file();
    return isset($env[$key]) ? trim((string) $env[$key]) : '';
  }

  private static function parse_env_file(): array {
    static $parsed = null;
    if (is_array($parsed)) {
      return $parsed;
    }

    $parsed = [];
    $env_path = trailingslashit(ABSPATH) . '.env';
    if (!is_readable($env_path)) {
      return $parsed;
    }

    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
      return $parsed;
    }

    foreach ($lines as $line) {
      if (!is_string($line)) continue;

      $line = trim($line);
      if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
        continue;
      }

      [$name, $value] = explode('=', $line, 2);
      $name = trim($name);
      $value = trim($value);
      if ($name === '') continue;

      $quote = substr($value, 0, 1);
      if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
        $value = substr($value, 1, -1);
      }

      $parsed[$name] = $value;
    }

    return $parsed;
  }

}