<?php

if (!defined('ABSPATH')) exit;

class AISB_Settings {
      public function admin_menu() {
    add_options_page('AI Sitemap Builder', 'AI Sitemap Builder', 'manage_options', 'ai-sitemap-builder', [$this, 'render_settings_page']);
  }

  public function register_settings() {
    register_setting('aisb_settings_group', AISB_Plugin::OPT_KEY, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize_settings'],
      'default' => []
    ]);

    add_settings_section('aisb_main', 'OpenAI API Settings', function() {
      echo '<p>Configure your OpenAI API credentials. Default endpoint is OpenAI Chat Completions.</p>';
    }, 'ai-sitemap-builder');

    $fields = [
      'api_key'  => ['OpenAI API Key', 'password', 'Your OpenAI API key (stored in wp_options).'],
      'endpoint' => ['Endpoint', 'text', 'Default: https://api.openai.com/v1/chat/completions'],
      'model'    => ['Model', 'text', 'Example: gpt-4o-mini, gpt-4.1-mini, etc.'],
      'timeout'  => ['Timeout (seconds)', 'number', 'Example: 30'],
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

        // Pretty print (keep it readable)
        $txt = wp_json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        echo '<textarea class="large-text code" rows="18" readonly>' . esc_textarea($txt ?: '[]') . '</textarea>';

        // Clear link (GET, nonce-protected) — avoids nested form
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

    foreach ($fields as $key => $cfg) {
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
  }

    public function sanitize_settings($input) {
    $out = [];
    $out['api_key']  = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';
    $out['endpoint'] = isset($input['endpoint']) ? esc_url_raw($input['endpoint']) : 'https://api.openai.com/v1/chat/completions';
    $out['model']    = isset($input['model']) ? sanitize_text_field($input['model']) : 'gpt-4o-mini';
    $out['timeout']  = isset($input['timeout']) ? max(5, min(120, (int)$input['timeout'])) : 30;
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
    ];
    $saved = get_option(AISB_Plugin::OPT_KEY, []);
    return array_merge($defaults, is_array($saved) ? $saved : []);
  }

}