<?php

if (!defined('ABSPATH')) exit;

/**
 * Slaat een beperkte debuglog op in WordPress options.
 *
 * De log helpt bij het nakijken van AI-requests en schemafouten. Gevoelige
 * waarden zoals API keys worden niet opgeslagen.
 */
class AISB_Logger {
  /**
   * Leest de huidige log uit wp_options.
   */
    private function get_debug_log() {
    $log = get_option(AISB_Plugin::LOG_OPT_KEY, []);
    return is_array($log) ? $log : [];
  }

  private function append_debug_log($entry) {
    if (!is_array($entry)) return;

    // Bewaar nooit API keys in de debuglog.
    if (isset($entry['api_key'])) unset($entry['api_key']);

    $log = $this->get_debug_log();

    // Voeg tijdsinformatie toe in WordPress-tijdzone en Unix-tijd.
    $entry['_ts'] = current_time('mysql'); // WP timezone
    $entry['_unix'] = time();

    array_unshift($log, $entry);
    $log = array_slice($log, 0, AISB_Plugin::LOG_MAX_ENTRIES);

    update_option(AISB_Plugin::LOG_OPT_KEY, $log, false);
  }

  private function clear_debug_log() {
    update_option(AISB_Plugin::LOG_OPT_KEY, [], false);
  }

  private function redact_large_text($txt, $max = 25000) {
    $txt = is_string($txt) ? $txt : '';
    $txt = trim($txt);
    if ($txt === '') return $txt;
    if (strlen($txt) <= $max) return $txt;
    return substr($txt, 0, $max) . "\n\n[TRUNCATED to {$max} chars]";
  }

  public function handle_clear_log() {
    if (!is_admin()) return;
    if (!current_user_can('manage_options')) return;

    // Verwerk de nonce-beveiligde GET-actie om de log te wissen.
    if (!isset($_GET['aisb_clear_log'])) return;

    if (
      !isset($_GET['aisb_clear_log_nonce']) ||
      !wp_verify_nonce($_GET['aisb_clear_log_nonce'], 'aisb_clear_log')
    ) {
      return;
    }

    $this->clear_debug_log();

    wp_safe_redirect(add_query_arg(['page' => 'ai-sitemap-builder', 'cleared' => 1], admin_url('options-general.php')));
    exit;
  }
}