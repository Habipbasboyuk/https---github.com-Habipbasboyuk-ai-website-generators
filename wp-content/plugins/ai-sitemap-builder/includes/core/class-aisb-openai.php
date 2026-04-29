<?php

if (!defined('ABSPATH')) exit;

class AISB_OpenAI {
      // ---------- OpenAI call + prompts (UPDATED with debug logging) ----------
    public function call_openai_chat_completions($user_prompt, $settings, $override_system_prompt = null) {
      $endpoint = $settings['endpoint'] ?: 'https://api.openai.com/v1/chat/completions';
      $api_key = $settings['api_key'];
      $model = $settings['model'] ?: 'gpt-4o-mini';
      $timeout = (int) $settings['timeout'];

      $system_prompt = ($override_system_prompt !== null) ? (string)$override_system_prompt : $this->system_prompt();
    
      $body = [
        'model' => $model,
        'messages' => [
          ['role' => 'system', 'content' => $system_prompt],
          ['role' => 'user', 'content' => $user_prompt],
        ],
        'temperature' => 0.4,
        // Forceer geldig JSON zodat het antwoord nooit halverwege wordt afgekapt
        // met losse tekst (anders crasht de JSON-parser en valt alle AI-tekst weg).
        'response_format' => [ 'type' => 'json_object' ],
        // Ruime output-limiet zodat grote multi-section JSON antwoorden compleet zijn.
        'max_tokens' => 16000,
      ];
    
      $args = [
        'headers' => [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => wp_json_encode($body),
        'timeout' => $timeout,
      ];
    
      $t0 = microtime(true);
      $response = wp_remote_post($endpoint, $args);
      $elapsed = round(microtime(true) - $t0, 3);
    
      if (is_wp_error($response)) {
        // Log transport-level WP error
        $this->append_debug_log([
          'event' => 'openai_http_wp_error',
          'model' => $model,
          'endpoint' => $endpoint,
          'timeout' => $timeout,
          'elapsed_s' => $elapsed,
          'system_prompt' => $this->redact_large_text($system_prompt, 12000),
          'user_prompt' => $this->redact_large_text($user_prompt, 25000),
          'wp_error' => $response->get_error_message(),
        ]);
        return $response;
      }
    
      $code = wp_remote_retrieve_response_code($response);
      $raw  = wp_remote_retrieve_body($response);
    
      if ($code < 200 || $code >= 300) {
        // Log HTTP error response
        $this->append_debug_log([
          'event' => 'openai_http_error',
          'model' => $model,
          'endpoint' => $endpoint,
          'timeout' => $timeout,
          'elapsed_s' => $elapsed,
          'http_status' => $code,
          'system_prompt' => $this->redact_large_text($system_prompt, 12000),
          'user_prompt' => $this->redact_large_text($user_prompt, 25000),
          'http_body' => $this->redact_large_text($raw, 25000),
        ]);
        return new WP_Error('aisb_api_error', 'OpenAI API error (' . $code . '): ' . $raw);
      }
    
      $json = json_decode($raw, true);
      if (!is_array($json)) {
        // Log bad JSON wrapper from OpenAI
        $this->append_debug_log([
          'event' => 'openai_bad_json_wrapper',
          'model' => $model,
          'endpoint' => $endpoint,
          'timeout' => $timeout,
          'elapsed_s' => $elapsed,
          'http_status' => $code,
          'system_prompt' => $this->redact_large_text($system_prompt, 12000),
          'user_prompt' => $this->redact_large_text($user_prompt, 25000),
          'http_body' => $this->redact_large_text($raw, 25000),
          'json_last_error' => function_exists('json_last_error_msg') ? json_last_error_msg() : json_last_error(),
        ]);
        return new WP_Error('aisb_api_bad_json', 'OpenAI response was not JSON: ' . $raw);
      }
    
      $content = $json['choices'][0]['message']['content'] ?? '';
      $content = trim((string)$content);
    
      // Strip possible fences
      $content = preg_replace('/^```json\s*/i', '', $content);
      $content = preg_replace('/^```\s*/', '', $content);
      $content = preg_replace('/\s*```$/', '', $content);
    
      if ($content === '') {
        $this->append_debug_log([
          'event' => 'openai_empty_content',
          'model' => $model,
          'endpoint' => $endpoint,
          'timeout' => $timeout,
          'elapsed_s' => $elapsed,
          'http_status' => $code,
          'system_prompt' => $this->redact_large_text($system_prompt, 12000),
          'user_prompt' => $this->redact_large_text($user_prompt, 25000),
          'http_body' => $this->redact_large_text($raw, 25000),
        ]);
        return new WP_Error('aisb_empty', 'Empty AI response.');
      }
    
      // ✅ Also log success at the transport/wrapper level (content only, no API key)
      $this->append_debug_log([
        'event' => 'openai_success',
        'model' => $model,
        'endpoint' => $endpoint,
        'timeout' => $timeout,
        'elapsed_s' => $elapsed,
        'http_status' => $code,
        'system_prompt' => $this->redact_large_text($system_prompt, 12000),
        'user_prompt' => $this->redact_large_text($user_prompt, 25000),
        'raw_ai_content' => $this->redact_large_text($content, 25000),
      ]);
    
      return $content;
    }

    private function system_prompt(): string {
  if (class_exists('AISB_Prompts')) {
    $p = new AISB_Prompts();
    if (method_exists($p, 'system_prompt')) {
      return (string) $p->system_prompt();
    }
  }
  return 'Return ONLY valid JSON. No markdown. No commentary.';
}

private function append_debug_log(array $entry): void {
  if (isset($entry['api_key'])) $entry['api_key'] = '[REDACTED]';
  $entry['ts'] = gmdate('c');

  $log = get_option('aisb_debug_log', []);
  if (!is_array($log)) $log = [];

  $log[] = $entry;
  $max = 50;
  if (count($log) > $max) $log = array_slice($log, -$max);

  update_option('aisb_debug_log', $log, false);
}

private function redact_large_text(string $text, int $maxLen = 12000): string {
  $text = (string) $text;
  if (strlen($text) <= $maxLen) return $text;
  return substr($text, 0, $maxLen) . "\n...[TRUNCATED]...";
}

}