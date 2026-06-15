<?php

if (!defined('ABSPATH')) exit;

/**
 * Publiceert gegenereerde sites naar InstaWP.
 *
 * Deze klasse maakt of hergebruikt een InstaWP clone, publiceert pagina's via
 * XML-RPC en gebruikt Bricks builder AJAX om Bricks JSON, global classes,
 * theme styles en variabelen betrouwbaar in de clone te schrijven.
 */
class AISB_InstaWP {
  private const AJAX_ACTION = 'aisb_publish_instawp_site';
  private const API_BASE = 'https://app.instawp.io/api/v2';
  private const XMLRPC_BLOG_ID = 1;
  private const PUBLISH_LOCK_TTL = 900;
  private const PUBLISH_SESSION_TTL = 7200;
  private const AUTO_LOGIN_ACTION = 'aisb_instawp_auto_login';
  private const AUTO_LOGIN_TTL = 300;
  private const LEGACY_TEMPLATE_SLUGS = [
    '22610' => 'bricks-ai-base',
  ];
  private const RUNTIME_SECTION_ATTRIBUTE = 'data-aisb-runtime-section';

  /** @var AISB_Wireframe_Compiler */
  private $compiler;

  public function __construct(AISB_Wireframe_Compiler $compiler) {
    $this->compiler = $compiler;
  }

  /**
    * Bricks postmeta-sleutels met page-builder data.
    *
    * Deze keys beginnen met een underscore en zijn daardoor protected meta in
    * WordPress. XML-RPC mag ze standaard niet schrijven zonder expliciete
    * auth_post_meta filters.
   */
  private const BRICKS_META_KEYS = [
    '_bricks_page_content_2',
    '_bricks_data',
    '_bricks_page_header_2',
    '_bricks_page_footer_2',
    '_bricks_editor_mode',
  ];

  public function init(): void {
    add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_publish_site']);
    add_action('admin_post_' . self::AUTO_LOGIN_ACTION, [$this, 'handle_auto_login_redirect']);
    $this->register_bricks_meta_auth();
  }

  /**
    * Staat toe dat Bricks page-builder meta via XML-RPC wordt geschreven.
    *
    * Zonder deze filters laat WordPress `_bricks_*` meta stilletjes vallen en
    * blijft de gepubliceerde Bricks-pagina leeg.
   */
  private function register_bricks_meta_auth(): void {
    foreach (self::BRICKS_META_KEYS as $meta_key) {
      add_filter("auth_post_meta_{$meta_key}", [$this, 'allow_bricks_meta_write'], 10, 4);
    }
  }

  /**
    * Geeft schrijfrecht voor Bricks meta aan gebruikers die de doelpost mogen bewerken.
   *
   * @param bool   $allowed  Whether the user can add/edit/delete the meta.
   * @param string $meta_key The meta key being checked.
   * @param int    $post_id  The post the meta belongs to.
   * @param int    $user_id  The user requesting access.
   * @return bool
   */
  public function allow_bricks_meta_write($allowed, $meta_key, $post_id, $user_id) {
    if ($allowed) {
      return $allowed;
    }

    return user_can((int) $user_id, 'edit_post', (int) $post_id);
  }

  public function handle_auto_login_redirect(): void {
    if (!is_user_logged_in()) {
      wp_die('Not logged in', '', ['response' => 403]);
    }

    $token = isset($_GET['token']) ? sanitize_key(wp_unslash($_GET['token'])) : '';
    $nonce = isset($_GET['_wpnonce']) ? (string) wp_unslash($_GET['_wpnonce']) : '';
    if ($token === '' || !wp_verify_nonce($nonce, self::AUTO_LOGIN_ACTION . '_' . $token)) {
      wp_die('Invalid auto-login link', '', ['response' => 403]);
    }

    $transient_key = $this->get_auto_login_transient_key($token);
    $payload = get_transient($transient_key);
    delete_transient($transient_key);

    if (!is_array($payload)) {
      wp_die('The auto-login link has expired. Publish the site again to create a new link.', '', ['response' => 410]);
    }

    $user_id = (int) ($payload['user_id'] ?? 0);
    if ($user_id !== (int) get_current_user_id() && !current_user_can('manage_options')) {
      wp_die('Forbidden', '', ['response' => 403]);
    }

    $site_url = untrailingslashit((string) ($payload['site_url'] ?? ''));
    $username = (string) ($payload['username'] ?? '');
    $password = (string) ($payload['password'] ?? '');
    $target_url = (string) ($payload['target_url'] ?? '');
    if ($site_url === '' || $username === '' || $password === '') {
      wp_die('Missing remote login credentials', '', ['response' => 500]);
    }

    if ($target_url === '') {
      $target_url = trailingslashit($site_url);
    }

    $login_url = trailingslashit($site_url) . 'wp-login.php';

    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <title>Opening site...</title>
  <style>
    body { align-items: center; background: #f6f7f7; color: #1d2327; display: flex; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; justify-content: center; min-height: 100vh; margin: 0; }
    main { text-align: center; }
    button { background: #2271b1; border: 0; border-radius: 4px; color: #fff; cursor: pointer; font-size: 14px; padding: 10px 16px; }
  </style>
</head>
<body>
  <main>
    <p>Opening your site...</p>
    <form id="aisb-remote-login" method="post" action="<?php echo esc_url($login_url); ?>" autocomplete="off">
      <input type="hidden" name="log" value="<?php echo esc_attr($username); ?>">
      <input type="hidden" name="pwd" value="<?php echo esc_attr($password); ?>">
      <input type="hidden" name="rememberme" value="forever">
      <input type="hidden" name="redirect_to" value="<?php echo esc_url($target_url); ?>">
      <input type="hidden" name="wp-submit" value="Log In">
      <noscript><button type="submit">Open site</button></noscript>
    </form>
  </main>
  <script>document.getElementById('aisb-remote-login').submit();</script>
</body>
</html>
    <?php
    exit;
  }

  public function ajax_publish_site(): void {
    if (function_exists('set_time_limit')) {
      @set_time_limit(600);
    }

    if (!is_user_logged_in()) {
      wp_send_json_error(['message' => 'Not logged in'], 401);
    }

    $nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';
    if (!$this->verify_nonce($nonce)) {
      wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }

    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $site_name_supplied = isset($_POST['site_name']) && trim((string) wp_unslash($_POST['site_name'])) !== '';
    $site_name = isset($_POST['site_name']) ? sanitize_title(wp_unslash($_POST['site_name'])) : '';
    $target_page_slug = isset($_POST['target_page_slug'])
      ? sanitize_title(wp_unslash($_POST['target_page_slug']))
      : $page_slug;
    $target_post_id = isset($_POST['target_post_id']) ? (int) $_POST['target_post_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int) $_POST['sitemap_version_id'] : 0;
    $export_raw = isset($_POST['export_payload']) ? (string) wp_unslash($_POST['export_payload']) : '';

    if (!$project_id) {
      wp_send_json_error(['message' => 'Missing project_id'], 400);
    }

    $project = get_post($project_id);
    if (!$project || $project->post_type !== 'aisb_project' || (int) $project->post_author !== (int) get_current_user_id()) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    if ($site_name === '') {
      $site_name = $this->default_site_name($project);
    }

    if (!$site_name_supplied) {
      $existing_session = $this->load_latest_publish_session($project_id);
      if (is_array($existing_session) && !empty($existing_session['site_name'])) {
        $site_name = sanitize_title((string) $existing_session['site_name']);
      }
    }

    if (!$this->acquire_publish_lock($project_id, $site_name)) {
      $existing_session = $this->load_latest_publish_session($project_id);
      $payload = [
        'message' => 'A publish is already running for this project. Wait for the current attempt to finish before starting another clone.',
        'code' => 'aisb_instawp_publish_locked',
      ];

      if (is_array($existing_session)) {
        $payload['wp_url'] = (string) ($existing_session['site']['site_url'] ?? '');
        $payload['wp_admin_url'] = (string) ($existing_session['site']['admin_url'] ?? '');
        $payload['site_id'] = (string) ($existing_session['site_id'] ?? '');
      }

      wp_send_json_error($payload, 409);
    }

    if (!$sitemap_version_id) {
      $sitemap_version_id = (int) get_post_meta($project_id, 'aisb_latest_sitemap_id', true);
    }

    $export = null;
    if ($export_raw !== '') {
      $export = json_decode($export_raw, true);
      if (!is_array($export) || empty($export['pages']) || !is_array($export['pages'])) {
        wp_send_json_error(['message' => 'The publish payload did not contain any pages.'], 400);
      }
    } else {
      if (!$page_slug) {
        wp_send_json_error(['message' => 'Missing page_slug'], 400);
      }
      if (!$target_page_slug && !$target_post_id) {
        $target_page_slug = $page_slug;
      }
      if (!$sitemap_version_id) {
        wp_send_json_error(['message' => 'Missing sitemap_version_id'], 400);
      }
    }

    try {
      if (is_array($export)) {
        $result = $this->publish_export_site($project, $export, $site_name);
      } else {
        $result = $this->publish_page([
          'project_id' => $project_id,
          'sitemap_version_id' => $sitemap_version_id,
          'page_slug' => $page_slug,
          'site_name' => $site_name,
          'target_page_slug' => $target_page_slug,
          'target_post_id' => $target_post_id,
        ]);
      }
    } finally {
      $this->release_publish_lock($project_id);
    }

    if (is_wp_error($result)) {
      $error_data = $result->get_error_data();
      $status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : (int) $error_data;
      if ($status < 400) $status = 500;
      wp_send_json_error([
        'message' => $result->get_error_message(),
        'code' => $result->get_error_code(),
      ], $status);
    }

    wp_send_json_success($result);
  }

  private function verify_nonce(string $nonce): bool {
    if ($nonce === '') return false;
    return (bool) (wp_verify_nonce($nonce, AISB_Plugin::NONCE_ACTION) || wp_verify_nonce($nonce, 'aisb_sg_nonce'));
  }

  private function normalise_instawp_timeout($timeout): int {
    $timeout = (int) $timeout;
    if ($timeout < 120) {
      $timeout = 240;
    }

    return min(300, $timeout);
  }

  private function default_site_name(WP_Post $project): string {
    $base = sanitize_title($project->post_title ?: 'aisb-site');
    if ($base === '') {
      $base = 'aisb-site';
    }

    return substr($base, 0, 40) . '-' . (int) $project->ID . '-' . gmdate('His');
  }

  private function acquire_publish_lock(int $project_id, string $site_name): bool {
    $key = $this->get_publish_lock_key($project_id);
    if (get_transient($key)) {
      return false;
    }

    set_transient($key, [
      'site_name' => $site_name,
      'started_at' => time(),
    ], self::PUBLISH_LOCK_TTL);

    return true;
  }

  private function release_publish_lock(int $project_id): void {
    delete_transient($this->get_publish_lock_key($project_id));
  }

  private function get_publish_lock_key(int $project_id): string {
    return 'aisb_instawp_publish_lock_' . $project_id;
  }

  private function get_publish_session_key(int $project_id): string {
    return 'aisb_instawp_publish_session_' . $project_id;
  }

  private function load_latest_publish_session(int $project_id): ?array {
    $session = get_transient($this->get_publish_session_key($project_id));
    return is_array($session) ? $session : null;
  }

  private function load_saved_publish_session(int $project_id, string $site_name): ?array {
    $session = $this->load_latest_publish_session($project_id);
    if (!is_array($session)) {
      return null;
    }

    if ((string) ($session['site_name'] ?? '') !== $site_name) {
      return null;
    }

    $site = isset($session['site']) && is_array($session['site']) ? $session['site'] : null;
    if (!$site) {
      return null;
    }

    foreach (['site_url', 'admin_url', 'xmlrpc_url', 'username', 'password'] as $required_key) {
      if (empty($site[$required_key]) || !is_string($site[$required_key])) {
        return null;
      }
    }

    return $session;
  }

  private function get_auto_login_transient_key(string $token): string {
    return 'aisb_instawp_auto_login_' . $token;
  }

  private function create_frontend_auto_login_url(array $site): string {
    $site_url = untrailingslashit((string) ($site['site_url'] ?? ''));
    $username = (string) ($site['username'] ?? '');
    $password = (string) ($site['password'] ?? '');

    if ($site_url === '' || $username === '' || $password === '') {
      return '';
    }

    $token = str_replace('-', '', wp_generate_uuid4());
    set_transient($this->get_auto_login_transient_key($token), [
      'user_id' => get_current_user_id(),
      'site_url' => $site_url,
      'username' => $username,
      'password' => $password,
      'target_url' => trailingslashit($site_url),
      'created_at' => time(),
    ], self::AUTO_LOGIN_TTL);

    return add_query_arg([
      'action' => self::AUTO_LOGIN_ACTION,
      'token' => $token,
      '_wpnonce' => wp_create_nonce(self::AUTO_LOGIN_ACTION . '_' . $token),
    ], admin_url('admin-post.php'));
  }

  private function extract_instawp_magic_login_url(array $payload): string {
    $url = $this->extract_first_value($payload, ['magic_login_url', 'magic_login', 'wp_auto_login_url', 'auto_login_url']);
    if ($url === '') {
      return '';
    }

    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    if ($path !== '' && preg_match('~/wp-login\.php$~i', $path)) {
      return '';
    }

    return $url;
  }

  private function store_publish_session(int $project_id, string $site_name, array $clone, array $site): void {
    set_transient($this->get_publish_session_key($project_id), [
      'site_name' => $site_name,
      'saved_at' => time(),
      'site_id' => $this->extract_first_value($clone, ['site_id', 'id']),
      'magic_login_url' => $this->extract_instawp_magic_login_url($clone),
      'clone' => $clone,
      'site' => $site,
    ], self::PUBLISH_SESSION_TTL);
  }

  private function clear_publish_session(int $project_id): void {
    delete_transient($this->get_publish_session_key($project_id));
  }

  private function get_or_create_publish_site(int $project_id, string $site_name, string $template_id, string $api_key, int $timeout) {
    $session = $this->load_saved_publish_session($project_id, $site_name);
    if (is_array($session)) {
      if ($this->instawp_site_payload_is_reachable($session, $timeout)) {
        $ready_site = $this->extract_wordpress_site_config($session);
        if (!is_wp_error($ready_site)) {
          return [
            'clone' => $session,
            'site' => $ready_site,
            'site_id' => (string) ($session['site_id'] ?? $this->extract_first_value($session, ['site_id', 'id'])),
            'magic_login_url' => (string) ($session['magic_login_url'] ?? $this->extract_instawp_magic_login_url($session)),
            'reused_existing_site' => true,
          ];
        }
      }

      error_log('[AISB] Discarding saved InstaWP publish session for "' . $site_name . '": host no longer resolves or site config is incomplete');
      $this->clear_publish_session($project_id);
    }

    $clone = $this->create_instant_site($template_id, $api_key, $site_name, $timeout);
    if (is_wp_error($clone)) {
      return $clone;
    }

    $clone = $this->await_instawp_site_ready($clone, $api_key, $timeout);
    if (is_wp_error($clone)) {
      return $clone;
    }

    $site = $this->extract_wordpress_site_config($clone);
    if (is_wp_error($site)) {
      return $site;
    }

    $this->store_publish_session($project_id, $site_name, $clone, $site);

    return [
      'clone' => $clone,
      'site' => $site,
      'site_id' => $this->extract_first_value($clone, ['site_id', 'id']),
      'magic_login_url' => $this->extract_instawp_magic_login_url($clone),
      'reused_existing_site' => false,
    ];
  }

  private function instawp_site_payload_is_reachable(array $payload, int $timeout): bool {
    $site_url = untrailingslashit($this->extract_first_value($payload, ['wp_url', 'site_url', 'url']));
    $username = $this->extract_first_value($payload, ['wp_username']);
    $password = $this->extract_first_value($payload, ['wp_password']);
    if ($site_url === '' || $username === '' || $password === '') {
      return false;
    }

    $host = (string) wp_parse_url($site_url, PHP_URL_HOST);
    if ($host === '') {
      return false;
    }

    $ip_address = $this->resolve_host_via_doh($host, max(10, min(30, $timeout)));
    if (is_wp_error($ip_address)) {
      return false;
    }

    return true;
  }

  /**
   * Wait until InstaWP keeps returning site credentials and the clone hostname
   * resolves via DoH. The create endpoint can return credentials while the site
   * is still provisioning; if we persist/reuse that payload too early, later
   * publishes keep hitting the same half-created clone and fail on `wp.getPages`
   * with `aisb_instawp_dns_failed`.
   *
   * @return array|WP_Error
   */
  private function await_instawp_site_ready(array $payload, string $api_key, int $timeout) {
    $site_id = $this->extract_first_value($payload, ['site_id', 'id']);
    $latest_payload = $payload;
    $deadline = time() + max(120, min(300, $timeout + 180));
    $attempt = 0;

    while (time() <= $deadline) {
      $attempt++;

      if ($site_id !== '') {
        $details = $this->fetch_instawp_site_details($site_id, $api_key, $timeout);
        if (!is_wp_error($details)) {
          $latest_payload = [
            'create' => $payload,
            'details' => $details,
          ];
        } else {
          error_log('[AISB] InstaWP readiness poll failed on attempt ' . $attempt . ': ' . $details->get_error_message());
        }
      }

      $site_url = untrailingslashit($this->extract_first_value($latest_payload, ['wp_url', 'site_url', 'url']));
      $username = $this->extract_first_value($latest_payload, ['wp_username']);
      $password = $this->extract_first_value($latest_payload, ['wp_password']);

      if ($site_url !== '' && $username !== '' && $password !== '') {
        $host = (string) wp_parse_url($site_url, PHP_URL_HOST);
        if ($host !== '') {
          $ip_address = $this->resolve_host_via_doh($host, $timeout);
          if (!is_wp_error($ip_address)) {
            error_log('[AISB] InstaWP site ready on attempt ' . $attempt . ': ' . $host . ' -> ' . $ip_address);
            return $latest_payload;
          }
        }
      }

      usleep(5000000);
    }

    return new WP_Error(
      'aisb_instawp_site_not_ready',
      'InstaWP accepted the site creation request but the new site hostname is not reachable yet. Try again in a minute.',
      [
        'status' => 502,
        'site_id' => $site_id,
        'site_url' => $this->extract_first_value($latest_payload, ['wp_url', 'site_url', 'url']),
      ]
    );
  }

  private function publish_export_site(WP_Post $project, array $export, string $site_name) {
    $pages = $this->normalise_export_pages($export['pages'] ?? []);
    if (empty($pages)) {
      return new WP_Error('aisb_instawp_empty_export', 'The live export does not contain any publishable pages.', ['status' => 400]);
    }

    $settings = AISB_Settings::get_settings();
    $api_key = trim((string) ($settings['instawp_api_key'] ?? ''));
    $template_id = trim((string) ($settings['instawp_template_id'] ?? ''));
    $timeout = $this->normalise_instawp_timeout($settings['instawp_timeout'] ?? 240);

    if ($api_key === '' || $template_id === '') {
      return new WP_Error(
        'aisb_instawp_missing_settings',
        'Configure INSTAWP_API_KEY and INSTAWP_TEMPLATE_SLUG (or legacy INSTAWP_TEMPLATE_ID) in .env or in the AI Sitemap Builder settings.',
        ['status' => 400]
      );
    }

    $publish_site = $this->get_or_create_publish_site((int) $project->ID, $site_name, $template_id, $api_key, $timeout);
    if (is_wp_error($publish_site)) {
      return $publish_site;
    }

    $clone = is_array($publish_site['clone'] ?? null) ? $publish_site['clone'] : [];
    $site = is_array($publish_site['site'] ?? null) ? $publish_site['site'] : [];

    $style_guide = $this->normalise_runtime_style_guide(
      isset($export['style_guide']) && is_array($export['style_guide']) ? $export['style_guide'] : []
    );
    $section_images = $this->build_export_section_image_map(
      $pages,
      isset($style_guide['images']) && is_array($style_guide['images']) ? $style_guide['images'] : []
    );
    $front_slug = $this->resolve_front_page_slug($pages);

    $global_classes = $this->normalise_global_classes_for_target(
      isset($export['global_classes']) && is_array($export['global_classes']) ? $export['global_classes'] : []
    );

    $theme_styles = $this->normalise_theme_styles_for_target(
      isset($export['theme_styles']) && is_array($export['theme_styles']) ? $export['theme_styles'] : []
    );

    $global_variables = isset($export['global_variables']) && is_array($export['global_variables'])
      ? array_values(array_filter($export['global_variables'], 'is_array'))
      : [];
    $global_variables_categories = isset($export['global_variables_categories']) && is_array($export['global_variables_categories'])
      ? array_values(array_filter($export['global_variables_categories'], 'is_array'))
      : [];
    $color_palette = isset($export['color_palette']) && is_array($export['color_palette'])
      ? array_values(array_filter($export['color_palette'], 'is_array'))
      : [];

    $publish_result = $this->publish_pages_via_xmlrpc(
      $site,
      $clone,
      $project,
      $pages,
      $style_guide,
      $section_images,
      $front_slug,
      $timeout,
      $global_classes,
      $theme_styles,
      $global_variables,
      $global_variables_categories,
      $color_palette
    );
    if (is_wp_error($publish_result)) {
      return $publish_result;
    }

    $this->clear_publish_session((int) $project->ID);

    return array_merge([
      'wp_url' => $site['site_url'],
      'wp_admin_url' => $site['admin_url'],
      'frontend_auto_login_url' => $this->create_frontend_auto_login_url($site),
      'magic_login_url' => (string) ($publish_site['magic_login_url'] ?? $this->extract_instawp_magic_login_url($clone)),
      'site_id' => (string) ($publish_site['site_id'] ?? $this->extract_first_value($clone, ['site_id', 'id'])),
      'reused_existing_site' => !empty($publish_site['reused_existing_site']),
    ], $publish_result);
  }

  private function normalise_export_pages($pages): array {
    if (!is_array($pages)) return [];

    $normalised = [];
    foreach ($pages as $page) {
      if (!is_array($page)) continue;

      $slug = sanitize_title($page['slug'] ?? $page['page_slug'] ?? '');
      if ($slug === '') continue;

      $title = sanitize_text_field($page['title'] ?? ucfirst(str_replace('-', ' ', $slug)));
      $sections = isset($page['sections']) && is_array($page['sections']) ? array_values($page['sections']) : [];

      $normalised[] = [
        'slug' => $slug,
        'title' => $title !== '' ? $title : ucfirst(str_replace('-', ' ', $slug)),
        'sections' => $sections,
      ];
    }

    return $normalised;
  }

  private function normalise_runtime_style_guide(array $guide): array {
    $colours = [];
    if (!empty($guide['colours']) && is_array($guide['colours'])) {
      foreach ($guide['colours'] as $colour) {
        if (!is_array($colour)) continue;
        $colours[] = [
          'name' => sanitize_text_field($colour['name'] ?? ''),
          'hex' => trim((string) ($colour['hex'] ?? '')),
        ];
      }
    }

    $images = [];
    if (!empty($guide['images']) && is_array($guide['images'])) {
      foreach ($guide['images'] as $image) {
        if (!is_array($image)) continue;

        $full = trim((string) ($image['full'] ?? $image['url'] ?? ''));
        $thumb = trim((string) ($image['thumb'] ?? $full));
        if ($full === '' && $thumb === '') continue;

        $images[] = [
          'full' => $this->convert_local_url_to_data_uri($full),
          'thumb' => $this->convert_local_url_to_data_uri($thumb),
        ];
      }
    }

    return [
      'headingFont' => sanitize_text_field($guide['headingFont'] ?? ''),
      'bodyFont' => sanitize_text_field($guide['bodyFont'] ?? ''),
      'typography' => $this->normalise_runtime_typography($guide['typography'] ?? []),
      'sectionBg1' => trim((string) ($guide['sectionBg1'] ?? '')),
      'sectionBg2' => trim((string) ($guide['sectionBg2'] ?? '')),
      'logoUrl' => $this->convert_local_url_to_data_uri(trim((string) ($guide['logoUrl'] ?? ''))),
      'colours' => $colours,
      'images' => $images,
    ];
  }

  private function normalise_runtime_typography($typography): array {
    if (!is_array($typography)) {
      return [];
    }

    $normalised = [];
    foreach ($typography as $item) {
      if (!is_array($item)) continue;
      $normalised[] = [
        'label' => sanitize_text_field($item['label'] ?? ''),
        'cls' => sanitize_text_field($item['cls'] ?? ''),
        'fontFamily' => $this->normalise_bricks_font_family((string) ($item['fontFamily'] ?? '')),
        'fontSize' => sanitize_text_field($item['fontSize'] ?? $item['font-size'] ?? ''),
        'fontWeight' => sanitize_text_field($item['fontWeight'] ?? $item['font-weight'] ?? ''),
        'lineHeight' => sanitize_text_field($item['lineHeight'] ?? $item['line-height'] ?? ''),
      ];
    }

    return $normalised;
  }

  private function build_export_section_image_map(array $pages, array $images): array {
    $map = [];
    if (empty($images)) return $map;

    $image_count = count($images);
    $image_index = 0;

    foreach ($pages as $page) {
      $slug = (string) ($page['slug'] ?? '');
      if ($slug === '') continue;

      $page_map = [];
      $sections = isset($page['sections']) && is_array($page['sections']) ? array_values($page['sections']) : [];
      foreach ($sections as $section_index => $section) {
        $slots = [];
        $count = is_array($section) ? (int) ($section['image_count'] ?? 0) : 0;
        for ($offset = 0; $offset < $count; $offset++) {
          $image = $images[$image_index % $image_count] ?? [];
          $url = trim((string) ($image['full'] ?? $image['thumb'] ?? ''));
          if ($url !== '') {
            $slots[] = $url;
          }
          $image_index++;
        }

        $page_map[(int) $section_index] = $slots;
      }

      $map[$slug] = $page_map;
    }

    return $map;
  }

  private function build_runtime_payload(
    array $page,
    array $all_pages,
    array $style_guide,
    array $page_section_images,
    string $front_slug,
    string $site_url
  ): array {
    $sections_payload = [];
    $sections = isset($page['sections']) && is_array($page['sections']) ? array_values($page['sections']) : [];

    foreach ($sections as $section_index => $section) {
      if (!is_array($section)) continue;

      $sections_payload[] = [
        'slot' => (string) $section_index,
        'type' => sanitize_key($section['type'] ?? 'generic'),
        'bg_color' => trim((string) ($section['bg_color'] ?? '')),
        'image_urls' => isset($page_section_images[$section_index]) && is_array($page_section_images[$section_index])
          ? array_values(array_filter(array_map('strval', $page_section_images[$section_index]), static function ($value): bool {
              return $value !== '';
            }))
          : [],
        'patch' => $this->normalise_runtime_patch_list(
          isset($section['patch']) && is_array($section['patch']) ? $section['patch'] : []
        ),
      ];
    }

    $pages_payload = [];
    foreach ($all_pages as $candidate) {
      if (!is_array($candidate)) continue;

      $slug = (string) ($candidate['slug'] ?? '');
      if ($slug === '') continue;

      $pages_payload[] = [
        'slug' => $slug,
        'title' => (string) ($candidate['title'] ?? ucfirst(str_replace('-', ' ', $slug))),
        'is_front' => $slug === $front_slug,
      ];
    }

    return [
      'page_slug' => (string) ($page['slug'] ?? ''),
      'site_url' => $site_url,
      'pages' => $pages_payload,
      'style_guide' => $style_guide,
      'sections' => $sections_payload,
    ];
  }

  private function normalise_runtime_patch_list(array $patches): array {
    $normalised = [];
    foreach ($patches as $patch) {
      if (!is_array($patch)) continue;

      $type = sanitize_key($patch['type'] ?? '');
      if (!in_array($type, ['mirror', 'text', 'img', 'css'], true)) continue;

      $entry = ['type' => $type];
      if (isset($patch['selector']) && is_scalar($patch['selector'])) {
        $selector = trim((string) $patch['selector']);
        if ($selector !== '') {
          $entry['selector'] = $selector;
        }
      }

      if ($type === 'mirror') {
        $entry['mirrored'] = !empty($patch['mirrored']);
      }

      if ($type === 'text' && isset($patch['text']) && is_scalar($patch['text'])) {
        $entry['text'] = (string) $patch['text'];
      }

      if ($type === 'img' && isset($patch['src']) && is_scalar($patch['src'])) {
        $entry['src'] = $this->convert_local_url_to_data_uri(trim((string) $patch['src']));
      }

      if ($type === 'css') {
        if (isset($patch['prop']) && is_scalar($patch['prop'])) {
          $entry['prop'] = trim((string) $patch['prop']);
        }
        if (array_key_exists('value', $patch) && is_scalar($patch['value'])) {
          $entry['value'] = (string) $patch['value'];
        }
        if (isset($patch['cascade']) && is_scalar($patch['cascade'])) {
          $entry['cascade'] = trim((string) $patch['cascade']);
        }
      }

      $normalised[] = $entry;
    }

    return $normalised;
  }

  private function build_legacy_export_page(array $model, string $page_slug, string $target_slug = ''): array {
    $slug = sanitize_title($target_slug !== '' ? $target_slug : $page_slug);
    if ($slug === '') {
      $slug = 'published-page';
    }

    $title = sanitize_text_field($model['page']['title'] ?? ucfirst(str_replace('-', ' ', $slug)));
    $sections = [];

    foreach (isset($model['sections']) && is_array($model['sections']) ? $model['sections'] : [] as $section) {
      if (!is_array($section)) continue;

      $section_title = sanitize_text_field($section['section_name'] ?? $section['title'] ?? '');
      $purpose = trim((string) ($section['purpose'] ?? ''));
      $key_content = isset($section['key_content']) && is_array($section['key_content'])
        ? array_values(array_filter(array_map('strval', $section['key_content']), static function ($value): bool {
            return trim($value) !== '';
          }))
        : [];

      $texts = [];
      if ($section_title !== '') {
        $texts[] = $section_title;
      }
      if ($purpose !== '') {
        $texts[] = $purpose;
      }
      if ($key_content) {
        $texts[] = '<ul>' . implode('', array_map(static function ($item): string {
          return '<li>' . esc_html($item) . '</li>';
        }, $key_content)) . '</ul>';
      }

      $sections[] = [
        'uuid' => sanitize_key($section['uuid'] ?? ''),
        'type' => sanitize_key($section['type'] ?? 'generic'),
        'content' => [
          'texts' => $texts,
          'images' => [],
        ],
        'patch' => [],
      ];
    }

    if (empty($sections)) {
      $sections[] = [
        'type' => 'generic',
        'content' => [
          'texts' => [$title, 'Published from AI Sitemap Builder.'],
          'images' => [],
        ],
      ];
    }

    return [
      'slug' => $slug,
      'title' => $title,
      'sections' => $sections,
    ];
  }

  private function publish_pages_via_xmlrpc(
    array $site,
    array $clone,
    $project,
    array $pages,
    array $style_guide,
    array $section_images,
    string $front_slug,
    int $timeout,
    array $global_classes = [],
    array $theme_styles = [],
    array $global_variables = [],
    array $global_variables_categories = [],
    array $color_palette = []
  ) {
    $existing_pages = $this->fetch_remote_pages($site, $timeout);
    if (is_wp_error($existing_pages)) {
      return $existing_pages;
    }

    $published_pages = [];
    $front_page_id = 0;
    $project_name = $project instanceof WP_Post ? (string) $project->post_title : '';
    $bricks_injections = [];

    foreach ($pages as $page_index => $page) {
      if (!is_array($page)) continue;

      $slug = sanitize_title($page['slug'] ?? '');
      if ($slug === '') continue;

      $page_section_images = isset($section_images[$slug]) && is_array($section_images[$slug])
        ? $section_images[$slug]
        : [];

      $html = $this->build_remote_page_html(
        $page,
        $pages,
        $style_guide,
        $page_section_images,
        $front_slug,
        $site['site_url'],
        $project_name
      );

      $content_struct = [
        'title' => (string) ($page['title'] ?? ucfirst(str_replace('-', ' ', $slug))),
        'description' => $html,
        'wp_slug' => $slug,
        'page_status' => 'publish',
        'wp_page_order' => (int) $page_index,
        'mt_allow_comments' => 0,
        'mt_allow_pings' => 0,
      ];

      $remote_page = $existing_pages[$slug] ?? null;
      $bricks_payload = null;
      if ($this->page_uses_bricks_export($page)) {
        $runtime_payload = $this->build_runtime_payload(
          $page,
          $pages,
          $style_guide,
          $page_section_images,
          $front_slug,
          $site['site_url']
        );
        $bricks_payload = $this->prepare_export_payload_for_target(
          $page,
          isset($remote_page['meta_keys']) && is_array($remote_page['meta_keys']) ? $remote_page['meta_keys'] : [],
          $runtime_payload,
          $page_section_images
        );
        $content_struct['custom_fields'] = $this->build_bricks_custom_fields(
          $bricks_payload,
          isset($remote_page['custom_fields']) && is_array($remote_page['custom_fields']) ? $remote_page['custom_fields'] : []
        );
        error_log('[AISB] Publishing Bricks meta for page "' . $slug . '" with keys: ' . implode(',', array_map(static function ($field): string {
          return (string) ($field['key'] ?? '');
        }, $content_struct['custom_fields'])));
      }

      $mode = 'created';
      if (is_array($remote_page) && !empty($remote_page['page_id'])) {
        $result = $this->call_xmlrpc(
          $site['xmlrpc_url'],
          $timeout,
          'wp.editPage',
          [self::XMLRPC_BLOG_ID, (int) $remote_page['page_id'], $site['username'], $site['password'], $content_struct, true],
          'update page "' . $slug . '"'
        );
        if (is_wp_error($result)) {
          return $result;
        }

        $page_id = (int) $remote_page['page_id'];
        $mode = 'updated';
      } else {
        $result = $this->call_xmlrpc(
          $site['xmlrpc_url'],
          $timeout,
          'wp.newPage',
          [self::XMLRPC_BLOG_ID, $site['username'], $site['password'], $content_struct],
          'create page "' . $slug . '"'
        );
        if (is_wp_error($result)) {
          return $result;
        }

        $page_id = (int) $result;
      }

      $existing_pages[$slug] = [
        'page_id' => $page_id,
        'wp_slug' => $slug,
        'title' => (string) ($content_struct['title'] ?? ''),
      ];

      if ($slug === $front_slug) {
        $front_page_id = $page_id;
      }

      if (is_array($bricks_payload) && !empty($bricks_payload['content'])) {
        $bricks_injections[] = [
          'post_id' => $page_id,
          'slug' => $slug,
          'payload' => $bricks_payload,
        ];
      }

      $published_pages[] = [
        'post_id' => $page_id,
        'slug' => $slug,
        'title' => (string) ($content_struct['title'] ?? ''),
        'mode' => $mode,
        'url' => $this->build_remote_page_url($site['site_url'], $slug),
      ];
    }

    $bricks_code_execution_ok = $this->configure_bricks_code_execution($site, $timeout);

    // The Bricks "Nav Menu" element references a WordPress menu by term id. The
    // exported elements point at the source site's menu id, which does not exist
    // on the fresh clone, so Bricks renders "No nav menu found.". Create a real
    // menu (with the published pages as items) on the clone and rewrite every
    // nav-menu element to use the new term id before injecting the Bricks data.
    $nav_menu_id = 0;
    $nav_menu_items = [];
    foreach ($published_pages as $published_page) {
      $title = trim((string) ($published_page['title'] ?? ''));
      if ($title === '') {
        $title = ucwords(str_replace('-', ' ', (string) ($published_page['slug'] ?? '')));
      }
      if ($title === '') continue;
      $nav_menu_items[] = [
        'title'     => $title,
        'object_id' => (int) ($published_page['post_id'] ?? 0),
        'url'       => (string) ($published_page['url'] ?? ''),
      ];
      if (count($nav_menu_items) >= 10) break;
    }

    if (!empty($nav_menu_items)) {
      $menu_label = $project_name !== '' ? $project_name . ' Menu' : 'Main Menu';
      $nav_menu_id = $this->configure_remote_nav_menu($clone, $site, $nav_menu_items, $menu_label, $timeout);
    }

    if ($nav_menu_id > 0 && !empty($bricks_injections)) {
      foreach ($bricks_injections as $injection_index => $injection) {
        $payload = is_array($injection['payload'] ?? null) ? $injection['payload'] : [];
        foreach (['content', 'header', 'footer'] as $region) {
          if (isset($payload[$region]) && is_array($payload[$region])) {
            $payload[$region] = $this->set_nav_menu_id_in_nodes($payload[$region], $nav_menu_id);
          }
        }
        $bricks_injections[$injection_index]['payload'] = $payload;
      }
    }

    // The Bricks page-builder data lives in protected `_bricks_*` post meta.
    // XML-RPC `set_custom_fields` silently refuses to write protected meta on a
    // vanilla WordPress + Bricks clone (our plugin is not installed there), so
    // the page would open blank in Bricks. Push the data through Bricks' own
    // `bricks_save_post` endpoint instead, which writes the meta directly.
    $bricks_injected = [];
    if (!empty($bricks_injections)) {
      $bricks_injected = $this->inject_bricks_via_builder($site, $bricks_injections, $timeout, $global_classes, $theme_styles, $global_variables, $global_variables_categories, $color_palette);
    }

    $blog_title_updated = false;
    if ($project_name !== '') {
      $blog_title_updated = $this->update_remote_blog_title($site, $project_name, $timeout);
    }

    $front_page_updated = false;
    if ($front_page_id > 0) {
      $front_page_updated = $this->set_remote_front_page($site, $front_page_id);
    }

    return [
      'transport' => 'xmlrpc-html',
      'front_page_id' => $front_page_id,
      'front_page_updated' => $front_page_updated,
      'blog_title_updated' => $blog_title_updated,
      'bricks_code_execution_configured' => $bricks_code_execution_ok,
      'nav_menu_id' => $nav_menu_id,
      'bricks_injected' => $bricks_injected,
      'published_pages' => $published_pages,
    ];
  }

  /**
   * Inject Bricks builder data into the cloned site through Bricks' own
   * `bricks_save_post` AJAX endpoint.
   *
   * The clone only ships the Bricks theme (not this plugin), so we cannot rely
   * on an `auth_post_meta_*` filter to let XML-RPC write the protected
   * `_bricks_*` meta. Instead we authenticate as the clone administrator, scrape
   * the builder nonce from the Bricks builder page, and replay the exact request
   * the Bricks builder makes when you click "Save". That handler calls
   * `update_post_meta()` directly, so the protected-meta restriction does not
   * apply.
   *
   * @return array<int, array{post_id:int, slug:string, ok:bool, message?:string}>
   */
  private function inject_bricks_via_builder(array $site, array $injections, int $timeout, array $global_classes = [], array $theme_styles = [], array $global_variables = [], array $global_variables_categories = [], array $color_palette = []): array {
    $results = [];

    $login = $this->remote_wordpress_login($site);
    if (is_wp_error($login)) {
      error_log('[AISB] Bricks injection login failed: ' . $login->get_error_message());
      foreach ($injections as $injection) {
        $results[] = [
          'post_id' => (int) ($injection['post_id'] ?? 0),
          'slug' => (string) ($injection['slug'] ?? ''),
          'ok' => false,
          'message' => 'login failed: ' . $login->get_error_message(),
        ];
      }
      return $results;
    }

    $cookies = $login['cookies'];
    $site_url = untrailingslashit((string) ($site['site_url'] ?? ''));
    $ajax_url = $site_url . '/wp-admin/admin-ajax.php';

    // Bricks global classes hold the styling that page elements reference via
    // `_cssGlobalClasses`. The clone template does not ship the project's
    // classes, so buttons/sections lose their styling. `bricks_save_post`
    // persists a `globalClasses` POST param to the BRICKS_DB_GLOBAL_CLASSES
    // option, so we attach the project's classes to the first save and stop once
    // they have been stored.
    $global_classes_sent = empty($global_classes);

    // Bricks theme styles (colours, typography, background colours, border
    // radii) live in the BRICKS_DB_THEME_STYLES option. The same
    // `bricks_save_post` endpoint persists a `themeStyles` POST param, so we
    // attach the project's theme styles to the first save and stop once they
    // have been stored. Theme styles only take visual effect when they carry an
    // "Entire website" condition, which normalise_theme_styles_for_target()
    // guarantees.
    $theme_styles_sent = empty($theme_styles);

    // Bricks global variables, their categories and the colour palette define
    // the `var(--xxx)` tokens that the global classes and theme styles
    // reference. Without them the clone resolves those vars to the framework's
    // defaults (wrong button/background/accent colours), so we ship them once on
    // the first successful save via the same endpoint.
    $globals_sent = empty($global_variables) && empty($global_variables_categories) && empty($color_palette);

    foreach ($injections as $injection) {
      $post_id = (int) ($injection['post_id'] ?? 0);
      $slug = (string) ($injection['slug'] ?? '');
      $payload = is_array($injection['payload'] ?? null) ? $injection['payload'] : [];
      if ($post_id <= 0 || empty($payload['content'])) {
        continue;
      }

      $nonce = $this->fetch_bricks_builder_nonce($site_url, $post_id, $cookies, $timeout);
      if ($nonce === '') {
        error_log('[AISB] Bricks injection: could not read builder nonce for post ' . $post_id . ' ("' . $slug . '")');
        $results[] = ['post_id' => $post_id, 'slug' => $slug, 'ok' => false, 'message' => 'missing builder nonce'];
        continue;
      }

      $body = [
        'action' => 'bricks_save_post',
        'nonce' => $nonce,
        'postId' => (string) $post_id,
        'content' => wp_json_encode($payload['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ];
      if (!empty($payload['header'])) {
        $body['header'] = wp_json_encode($payload['header'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      }
      if (!empty($payload['footer'])) {
        $body['footer'] = wp_json_encode($payload['footer'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      }

      $includes_theme_styles = false;
      if (!$theme_styles_sent) {
        $body['themeStyles'] = wp_json_encode($theme_styles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $includes_theme_styles = true;
      }

      $includes_globals = false;
      if (!$globals_sent) {
        if (!empty($global_variables)) {
          $body['globalVariables'] = wp_json_encode(array_values($global_variables), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (!empty($global_variables_categories)) {
          $body['globalVariablesCategories'] = wp_json_encode(array_values($global_variables_categories), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (!empty($color_palette)) {
          $body['colorPalette'] = wp_json_encode(array_values($color_palette), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $includes_globals = true;
      }

      $includes_global_classes = false;
      if (!$global_classes_sent) {
        $body['globalClasses'] = wp_json_encode(array_values($global_classes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $body['globalClassesTimestamp'] = (string) (time() * 1000);
        $includes_global_classes = true;
      }

      $response = $this->remote_request($ajax_url, [
        'method' => 'POST',
        'timeout' => max(20, min(120, $timeout)),
        'cookies' => $cookies,
        'headers' => [
          'Referer' => $site_url . '/?page_id=' . $post_id . '&bricks=run',
          'X-Requested-With' => 'XMLHttpRequest',
        ],
        'body' => $body,
      ]);

      if (is_wp_error($response)) {
        error_log('[AISB] Bricks injection transport error for post ' . $post_id . ': ' . $response->get_error_message());
        $results[] = ['post_id' => $post_id, 'slug' => $slug, 'ok' => false, 'message' => $response->get_error_message()];
        continue;
      }

      $code = (int) ($response['code'] ?? 0);
      $decoded = json_decode((string) ($response['body'] ?? ''), true);
      $ok = $code === 200 && is_array($decoded) && !empty($decoded['success']);

      if (!$ok) {
        error_log('[AISB] Bricks injection failed for post ' . $post_id . ' (HTTP ' . $code . '): ' . substr((string) ($response['body'] ?? ''), 0, 500));
        $results[] = ['post_id' => $post_id, 'slug' => $slug, 'ok' => false, 'message' => 'HTTP ' . $code];
        continue;
      }

      error_log('[AISB] Bricks injection succeeded for post ' . $post_id . ' ("' . $slug . '")');
      if ($includes_global_classes) {
        $global_classes_sent = true;
        error_log('[AISB] Bricks injection: transferred ' . count($global_classes) . ' global classes to the clone.');
      }
      if ($includes_theme_styles) {
        $theme_styles_sent = true;
        error_log('[AISB] Bricks theme styles imported: ' . count($theme_styles) . ' style set(s) transferred to the clone.');
      }
      if ($includes_globals) {
        $globals_sent = true;
        error_log('[AISB] Bricks global variables imported: ' . count($global_variables) . ' variable(s), ' . count($global_variables_categories) . ' categorie(s), ' . count($color_palette) . ' palette(s) transferred to the clone.');
      }
      $results[] = ['post_id' => $post_id, 'slug' => $slug, 'ok' => true];
    }

    return $results;
  }

  /**
   * Enable code execution in Bricks Builder for all user roles on the cloned
   * site. Fetches the Bricks settings admin page to scrape the nonce, then
   * saves the code-execution settings via Bricks' own AJAX endpoint so the
   * published site is immediately usable with code elements. Non-fatal: logs
   * on failure but never aborts the publish.
   */
  private function configure_bricks_code_execution(array $site, int $timeout): bool {
    $login = $this->remote_wordpress_login($site);
    if (is_wp_error($login)) {
      error_log('[AISB] Bricks settings: login failed: ' . $login->get_error_message());
      return false;
    }

    $cookies      = $login['cookies'];
    $site_url     = untrailingslashit((string) ($site['site_url'] ?? ''));
    $ajax_url     = $site_url . '/wp-admin/admin-ajax.php';
    $settings_url = $site_url . '/wp-admin/admin.php?page=bricks-settings';

    $page_response = $this->remote_request($settings_url, [
      'method'      => 'GET',
      'timeout'     => max(20, min(60, $timeout)),
      'redirection' => 3,
      'cookies'     => $cookies,
    ]);

    if (is_wp_error($page_response)) {
      error_log('[AISB] Bricks settings: could not fetch settings page: ' . $page_response->get_error_message());
      return false;
    }

    $html  = (string) ($page_response['body'] ?? '');
    $nonce = $this->extract_bricks_admin_nonce($html);

    if ($nonce === '') {
      error_log('[AISB] Bricks settings: Bricks nonce not found on settings page.');
      return false;
    }

    $form_data = $this->build_bricks_settings_form_data($html, ['administrator', 'editor', 'author', 'contributor']);
    if ($form_data === '') {
      error_log('[AISB] Bricks settings: could not build settings form payload.');
      return false;
    }

    $save_response = $this->remote_request($ajax_url, [
      'method'  => 'POST',
      'timeout' => max(20, min(60, $timeout)),
      'cookies' => $cookies,
      'headers' => [
        'Referer'          => $settings_url,
        'X-Requested-With' => 'XMLHttpRequest',
      ],
      'body' => [
        'action'   => 'bricks_save_settings',
        'nonce'    => $nonce,
        'formData' => $form_data,
      ],
    ]);

    if (is_wp_error($save_response)) {
      error_log('[AISB] Bricks settings: AJAX call failed: ' . $save_response->get_error_message());
      return false;
    }

    $code    = (int) ($save_response['code'] ?? 0);
    $decoded = json_decode((string) ($save_response['body'] ?? ''), true);
    $ok      = $code === 200 && is_array($decoded) && !empty($decoded['success']);

    if ($ok) {
      error_log('[AISB] Bricks code execution enabled for all roles on the clone.');
    } else {
      error_log('[AISB] Bricks settings: save failed (HTTP ' . $code . '): ' . substr((string) ($save_response['body'] ?? ''), 0, 300));
    }

    return $ok;
  }

  /**
   * Maak een WordPress navigatiemenu aan op de geclonede site en geef het term id terug.
   * Probeert eerst via de WordPress REST API (betrouwbaar, vereist geen database credentials).
   * Val terug op directe database-verbinding als REST niet werkt.
   *
   * @param array<int, array{title:string, object_id:int, url?:string}> $menu_items
   */
  private function configure_remote_nav_menu(array $clone, array $site, array $menu_items, string $menu_name, int $timeout): int {
    $menu_items = array_values(array_filter($menu_items, static function ($item): bool {
      return is_array($item) && trim((string) ($item['title'] ?? '')) !== '';
    }));
    if (empty($menu_items)) return 0;

    // Primaire aanpak: WordPress REST API (vereist geen database credentials)
    $menu_id = $this->configure_remote_nav_menu_via_rest($site, $menu_items, $menu_name, $timeout);
    if ($menu_id > 0) {
      return $menu_id;
    }

    error_log('[AISB] Nav menu: REST API aanpak mislukt, probeer directe database...');

    // Fallback: directe database verbinding
    return $this->configure_remote_nav_menu_via_db($clone, $menu_items, $menu_name);
  }

  /**
   * Maak het navigatiemenu aan via de WordPress REST API.
   * Gebruik: login → REST nonce → POST /wp-json/wp/v2/menus → POST /wp-json/wp/v2/menu-items
   *
   * @param array<int, array{title:string, object_id:int, url?:string}> $menu_items
   */
  private function configure_remote_nav_menu_via_rest(array $site, array $menu_items, string $menu_name, int $timeout): int {
    $login = $this->remote_wordpress_login($site);
    if (is_wp_error($login)) {
      error_log('[AISB] Nav menu REST: login mislukt: ' . $login->get_error_message());
      return 0;
    }

    $cookies  = $login['cookies'];
    $site_url = untrailingslashit((string) ($site['site_url'] ?? ''));
    $rest_url = $site_url . '/wp-json/wp/v2';

    // REST nonce ophalen vanuit de WordPress admin dashboard pagina
    $nonce = $this->fetch_wp_rest_nonce($site_url, $cookies, $timeout);
    if ($nonce === '') {
      error_log('[AISB] Nav menu REST: kon geen REST nonce ophalen.');
      return 0;
    }

    $headers = [
      'X-WP-Nonce'       => $nonce,
      'Content-Type'     => 'application/json',
      'X-Requested-With' => 'XMLHttpRequest',
    ];

    // Menu aanmaken via REST API
    $menu_slug = sanitize_title($menu_name);
    if ($menu_slug === '') $menu_slug = 'main-menu';

    $create_response = $this->remote_request($rest_url . '/menus', [
      'method'  => 'POST',
      'timeout' => max(15, min(60, $timeout)),
      'cookies' => $cookies,
      'headers' => $headers,
      'body'    => wp_json_encode(['name' => $menu_name, 'slug' => $menu_slug], JSON_UNESCAPED_UNICODE),
    ]);

    if (is_wp_error($create_response)) {
      error_log('[AISB] Nav menu REST: menu aanmaken mislukt: ' . $create_response->get_error_message());
      return 0;
    }

    $code    = (int) ($create_response['code'] ?? 0);
    $decoded = json_decode((string) ($create_response['body'] ?? ''), true);

    if (($code !== 200 && $code !== 201) || !is_array($decoded) || empty($decoded['id'])) {
      error_log('[AISB] Nav menu REST: menu aanmaken HTTP ' . $code . ': ' . substr((string) ($create_response['body'] ?? ''), 0, 300));
      return 0;
    }

    $menu_id = (int) $decoded['id'];
    error_log('[AISB] Nav menu REST: menu aangemaakt id=' . $menu_id . ' naam=' . $menu_name);

    // Menu-items toevoegen
    foreach ($menu_items as $order => $item) {
      $object_id = (int) ($item['object_id'] ?? 0);
      $title     = sanitize_text_field((string) ($item['title'] ?? ''));
      $url       = (string) ($item['url'] ?? '');

      $item_body = [
        'title'      => ['rendered' => $title, 'raw' => $title],
        'menus'      => $menu_id,
        'status'     => 'publish',
        'menu_order' => $order + 1,
      ];

      if ($object_id > 0) {
        $item_body['type']      = 'post_type';
        $item_body['object']    = 'page';
        $item_body['object_id'] = $object_id;
        $item_body['url']       = $url;
      } else {
        $item_body['type'] = 'custom';
        $item_body['url']  = $url;
      }

      $item_response = $this->remote_request($rest_url . '/menu-items', [
        'method'  => 'POST',
        'timeout' => max(10, min(30, $timeout)),
        'cookies' => $cookies,
        'headers' => $headers,
        'body'    => wp_json_encode($item_body, JSON_UNESCAPED_UNICODE),
      ]);

      if (is_wp_error($item_response)) {
        error_log('[AISB] Nav menu REST: item "' . $title . '" mislukt: ' . $item_response->get_error_message());
        continue;
      }

      $item_code = (int) ($item_response['code'] ?? 0);
      if ($item_code !== 200 && $item_code !== 201) {
        error_log('[AISB] Nav menu REST: item "' . $title . '" HTTP ' . $item_code . ': ' . substr((string) ($item_response['body'] ?? ''), 0, 200));
      }
    }

    // Probeer het menu toe te wijzen aan beschikbare theme locaties (niet fataal)
    $this->assign_menu_to_locations_via_rest($site_url, $cookies, $headers, $menu_id, $timeout);

    return $menu_id;
  }

  /**
   * Haal de WordPress REST API nonce op van de admin dashboard pagina.
   * WordPress injecteert dit in het wpApiSettings object op elke admin-pagina.
   */
  private function fetch_wp_rest_nonce(string $site_url, array $cookies, int $timeout): string {
    $response = $this->remote_request($site_url . '/wp-admin/index.php', [
      'method'      => 'GET',
      'timeout'     => max(15, min(60, $timeout)),
      'redirection' => 3,
      'cookies'     => $cookies,
    ]);

    if (is_wp_error($response)) {
      return '';
    }

    $html = (string) ($response['body'] ?? '');
    if ($html === '') return '';

    // wpApiSettings object zoeken — aanwezig op elke WordPress admin pagina
    if (preg_match('/wpApiSettings\s*=\s*\{[^}]{0,800}"nonce"\s*:\s*"([a-zA-Z0-9]+)"/', $html, $m)) {
      return $m[1];
    }
    // Fallback: standalone nonce veld
    if (preg_match('/"rest_nonce"\s*:\s*"([a-zA-Z0-9]+)"/', $html, $m)) {
      return $m[1];
    }

    return '';
  }

  /**
   * Wijs het menu toe aan de beschikbare WordPress theme locaties via REST API.
   * Niet-fataal: logt fouten maar onderbreekt de publish niet.
   */
  private function assign_menu_to_locations_via_rest(string $site_url, array $cookies, array $headers, int $menu_id, int $timeout): void {
    $rest_url = $site_url . '/wp-json/wp/v2';

    // Lijst van beschikbare locaties ophalen
    $loc_response = $this->remote_request($rest_url . '/menu-locations', [
      'method'  => 'GET',
      'timeout' => max(10, min(30, $timeout)),
      'cookies' => $cookies,
      'headers' => $headers,
    ]);

    if (is_wp_error($loc_response) || (int) ($loc_response['code'] ?? 0) !== 200) {
      error_log('[AISB] Nav menu: menu-locations endpoint niet bereikbaar (niet-fataal).');
      return;
    }

    $locations = json_decode((string) ($loc_response['body'] ?? ''), true);
    if (!is_array($locations) || empty($locations)) {
      error_log('[AISB] Nav menu: geen theme locaties gevonden.');
      return;
    }

    $assigned_count = 0;
    foreach ($locations as $location) {
      if ($assigned_count >= 3) break; // Beperk tot 3 locaties om timeout te vermijden

      $location_slug = $location['slug'] ?? $location['name'] ?? '';
      if ($location_slug === '') continue;

      $assign_response = $this->remote_request($rest_url . '/menu-locations/' . rawurlencode($location_slug), [
        'method'  => 'POST',
        'timeout' => max(10, min(20, $timeout)),
        'cookies' => $cookies,
        'headers' => $headers,
        'body'    => wp_json_encode(['menus' => $menu_id]),
      ]);

      $assign_code = is_wp_error($assign_response) ? 0 : (int) ($assign_response['code'] ?? 0);
      if ($assign_code === 200 || $assign_code === 201) {
        $assigned_count++;
        error_log('[AISB] Nav menu: toegewezen aan locatie "' . $location_slug . '"');
      } else {
        error_log('[AISB] Nav menu: locatie "' . $location_slug . '" HTTP ' . $assign_code . ' (niet-fataal).');
      }
    }
  }

  /**
   * Fallback: Create a real WordPress navigation menu on the cloned site via
   * direct database connection. Used when REST API is not available.
   *
   * @param array<int, array{title:string, object_id:int, url?:string}> $menu_items
   */
  private function configure_remote_nav_menu_via_db(array $clone, array $menu_items, string $menu_name): int {
    $menu_items = array_values(array_filter($menu_items, static function ($item): bool {
      return is_array($item) && trim((string) ($item['title'] ?? '')) !== '';
    }));
    if (empty($menu_items)) {
      return 0;
    }

    $config = $this->extract_database_config($clone);
    if (is_wp_error($config)) {
      error_log('[AISB] Nav menu: database config unavailable: ' . $config->get_error_message());
      return 0;
    }

    $connection = $this->connect_database($config);
    if (is_wp_error($connection)) {
      error_log('[AISB] Nav menu: database connection failed: ' . $connection->get_error_message());
      return 0;
    }

    $prefix = $this->detect_table_prefix($connection, (string) ($config['prefix'] ?? ''));
    if (is_wp_error($prefix)) {
      error_log('[AISB] Nav menu: table prefix detection failed: ' . $prefix->get_error_message());
      $connection->close();
      return 0;
    }

    $menu_name = trim($menu_name) !== '' ? trim($menu_name) : 'Main Menu';
    $menu_slug = sanitize_title($menu_name);
    if ($menu_slug === '') {
      $menu_slug = 'main-menu';
    }

    $terms_table              = $prefix . 'terms';
    $term_taxonomy_table      = $prefix . 'term_taxonomy';
    $posts_table              = $prefix . 'posts';
    $postmeta_table           = $prefix . 'postmeta';
    $term_relationships_table = $prefix . 'term_relationships';

    $term_id = 0;
    $term_taxonomy_id = 0;

    $stmt = $connection->prepare("SELECT t.term_id, tt.term_taxonomy_id FROM {$terms_table} t INNER JOIN {$term_taxonomy_table} tt ON tt.term_id=t.term_id WHERE tt.taxonomy='nav_menu' AND (t.slug=? OR t.name=?) ORDER BY t.term_id ASC LIMIT 1");
    if ($stmt) {
      $stmt->bind_param('ss', $menu_slug, $menu_name);
      $stmt->execute();
      $result = $stmt->get_result();
      $row = $result ? $result->fetch_assoc() : null;
      $stmt->close();
      if ($row) {
        $term_id = (int) ($row['term_id'] ?? 0);
        $term_taxonomy_id = (int) ($row['term_taxonomy_id'] ?? 0);
      }
    }

    if ($term_id <= 0 || $term_taxonomy_id <= 0) {
      $term_group = 0;
      $stmt = $connection->prepare("INSERT INTO {$terms_table} (name, slug, term_group) VALUES (?, ?, ?)");
      if (!$stmt) {
        error_log('[AISB] Nav menu: term insert prepare failed: ' . $connection->error);
        $connection->close();
        return 0;
      }
      $stmt->bind_param('ssi', $menu_name, $menu_slug, $term_group);
      $stmt->execute();
      $error = $stmt->error;
      $term_id = (int) $connection->insert_id;
      $stmt->close();

      if ($error !== '' || $term_id <= 0) {
        error_log('[AISB] Nav menu: term insert failed: ' . $error);
        $connection->close();
        return 0;
      }

      $taxonomy = 'nav_menu';
      $description = '';
      $parent = 0;
      $count = 0;
      $stmt = $connection->prepare("INSERT INTO {$term_taxonomy_table} (term_id, taxonomy, description, parent, count) VALUES (?, ?, ?, ?, ?)");
      if (!$stmt) {
        error_log('[AISB] Nav menu: term taxonomy insert prepare failed: ' . $connection->error);
        $connection->close();
        return 0;
      }
      $stmt->bind_param('issii', $term_id, $taxonomy, $description, $parent, $count);
      $stmt->execute();
      $error = $stmt->error;
      $term_taxonomy_id = (int) $connection->insert_id;
      $stmt->close();

      if ($error !== '' || $term_taxonomy_id <= 0) {
        error_log('[AISB] Nav menu: term taxonomy insert failed: ' . $error);
        $connection->close();
        return 0;
      }
    } else {
      $existing_ids = [];
      $stmt = $connection->prepare("SELECT p.ID FROM {$posts_table} p INNER JOIN {$term_relationships_table} tr ON tr.object_id=p.ID WHERE tr.term_taxonomy_id=? AND p.post_type='nav_menu_item'");
      if ($stmt) {
        $stmt->bind_param('i', $term_taxonomy_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
          $existing_ids[] = (int) ($row['ID'] ?? 0);
        }
        $stmt->close();
      }

      foreach (array_filter($existing_ids) as $existing_id) {
        $stmt = $connection->prepare("DELETE FROM {$postmeta_table} WHERE post_id=?");
        if ($stmt) {
          $stmt->bind_param('i', $existing_id);
          $stmt->execute();
          $stmt->close();
        }
        $stmt = $connection->prepare("DELETE FROM {$term_relationships_table} WHERE object_id=?");
        if ($stmt) {
          $stmt->bind_param('i', $existing_id);
          $stmt->execute();
          $stmt->close();
        }
        $stmt = $connection->prepare("DELETE FROM {$posts_table} WHERE ID=? AND post_type='nav_menu_item'");
        if ($stmt) {
          $stmt->bind_param('i', $existing_id);
          $stmt->execute();
          $stmt->close();
        }
      }
    }

    $position = 0;
    foreach ($menu_items as $item) {
      $position++;
      $object_id = (int) ($item['object_id'] ?? 0);
      $url       = (string) ($item['url'] ?? '');
      $is_post   = $object_id > 0;

      $title = sanitize_text_field((string) ($item['title'] ?? ''));
      $post_name = sanitize_title($title) . '-' . $position;
      $now = gmdate('Y-m-d H:i:s');
      $author = 1;
      $empty = '';
      $closed = 'closed';
      $status = 'publish';
      $post_type = 'nav_menu_item';
      $post_parent = 0;
      $comment_count = 0;

      $stmt = $connection->prepare("INSERT INTO {$posts_table} (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
      if (!$stmt) {
        error_log('[AISB] Nav menu: menu item post prepare failed: ' . $connection->error);
        $connection->close();
        return 0;
      }
      $stmt->bind_param('isssssssssssssssisissi', $author, $now, $now, $empty, $title, $empty, $status, $closed, $closed, $empty, $post_name, $empty, $empty, $now, $now, $empty, $post_parent, $empty, $position, $post_type, $empty, $comment_count);
      $stmt->execute();
      $error = $stmt->error;
      $menu_item_id = (int) $connection->insert_id;
      $stmt->close();
      if ($error !== '' || $menu_item_id <= 0) {
        error_log('[AISB] Nav menu: menu item post insert failed: ' . $error);
        $connection->close();
        return 0;
      }

      $stmt = $connection->prepare("INSERT INTO {$term_relationships_table} (object_id, term_taxonomy_id, term_order) VALUES (?, ?, ?)");
      if (!$stmt) {
        error_log('[AISB] Nav menu: relationship prepare failed: ' . $connection->error);
        $connection->close();
        return 0;
      }
      $term_order = 0;
      $stmt->bind_param('iii', $menu_item_id, $term_taxonomy_id, $term_order);
      $stmt->execute();
      $error = $stmt->error;
      $stmt->close();
      if ($error !== '') {
        error_log('[AISB] Nav menu: relationship insert failed: ' . $error);
        $connection->close();
        return 0;
      }

      $meta_values = [
        '_menu_item_type'             => $is_post ? 'post_type' : 'custom',
        '_menu_item_menu_item_parent' => '0',
        '_menu_item_object_id'        => $is_post ? (string) $object_id : '0',
        '_menu_item_object'           => $is_post ? 'page' : 'custom',
        '_menu_item_target'           => '',
        '_menu_item_classes'          => maybe_serialize(['']),
        '_menu_item_xfn'              => '',
        '_menu_item_url'              => $is_post ? '' : $url,
      ];

      foreach ($meta_values as $meta_key => $meta_value) {
        $stored = $this->upsert_meta_value($connection, $prefix, $menu_item_id, $meta_key, (string) $meta_value);
        if (is_wp_error($stored)) {
          error_log('[AISB] Nav menu: meta write failed: ' . $stored->get_error_message());
          $connection->close();
          return 0;
        }
      }
    }

    $count = count($menu_items);
    $stmt = $connection->prepare("UPDATE {$term_taxonomy_table} SET count=? WHERE term_taxonomy_id=?");
    if ($stmt) {
      $stmt->bind_param('ii', $count, $term_taxonomy_id);
      $stmt->execute();
      $stmt->close();
    }

    error_log('[AISB] Nav menu created in clone database (term id ' . $term_id . ') with ' . $count . ' items.');

    // Wijs het menu ook toe aan de WordPress theme-locaties zodat het zichtbaar is
    // onder Appearance > Menus en klassieke thema-headers het menu ook kunnen laden.
    $this->assign_menu_to_theme_locations($connection, $prefix, $term_id);

    $connection->close();
    return $term_id;
  }

  /**
   * Wijs het gegenereerde nav menu toe aan de actieve WordPress theme locaties
   * (nav_menu_locations in theme_mods) zodat het direct beschikbaar is onder
   * Appearance > Menus en in de WordPress customizer.
   */
  private function assign_menu_to_theme_locations($connection, string $prefix, int $term_id): void {
    if ($term_id <= 0) return;

    $options_table = $prefix . 'options';

    // Actief thema ophalen uit de options tabel
    $stmt = $connection->prepare("SELECT option_value FROM {$options_table} WHERE option_name='stylesheet' LIMIT 1");
    if (!$stmt) {
      error_log('[AISB] Nav menu locations: could not query active theme.');
      return;
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row || empty($row['option_value'])) {
      error_log('[AISB] Nav menu locations: active theme not found in options.');
      return;
    }

    $theme_slug = (string) $row['option_value'];
    $theme_mods_key = 'theme_mods_' . $theme_slug;

    // Bestaande theme mods ophalen
    $stmt = $connection->prepare("SELECT option_value FROM {$options_table} WHERE option_name=? LIMIT 1");
    if (!$stmt) {
      error_log('[AISB] Nav menu locations: could not query theme_mods.');
      return;
    }
    $stmt->bind_param('s', $theme_mods_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $theme_mods = [];
    if ($existing && !empty($existing['option_value'])) {
      $decoded = maybe_unserialize($existing['option_value']);
      if (is_array($decoded)) {
        $theme_mods = $decoded;
      }
    }

    // Bepaal welke locaties al geregistreerd zijn; vul ze allemaal in met ons menu
    $locations = isset($theme_mods['nav_menu_locations']) && is_array($theme_mods['nav_menu_locations'])
      ? $theme_mods['nav_menu_locations']
      : [];

    if (empty($locations)) {
      // Meest voorkomende WordPress/Bricks locatienamen als fallback
      $locations = [
        'primary'     => $term_id,
        'main'        => $term_id,
        'header'      => $term_id,
        'main-menu'   => $term_id,
        'top-menu'    => $term_id,
      ];
    } else {
      // Wijs ons nieuwe menu toe aan elke beschikbare locatie
      foreach ($locations as $location => $existing_id) {
        $locations[$location] = $term_id;
      }
    }

    $theme_mods['nav_menu_locations'] = $locations;
    $serialized = serialize($theme_mods);

    if ($existing) {
      $stmt = $connection->prepare("UPDATE {$options_table} SET option_value=? WHERE option_name=?");
      if ($stmt) {
        $stmt->bind_param('ss', $serialized, $theme_mods_key);
        $stmt->execute();
        $stmt->close();
      }
    } else {
      $autoload = 'yes';
      $stmt = $connection->prepare("INSERT INTO {$options_table} (option_name, option_value, autoload) VALUES (?, ?, ?)");
      if ($stmt) {
        $stmt->bind_param('sss', $theme_mods_key, $serialized, $autoload);
        $stmt->execute();
        $stmt->close();
      }
    }

    error_log('[AISB] Nav menu (term_id=' . $term_id . ') assigned to theme locations: ' . implode(', ', array_keys($locations)));
  }

  /**
   * Point every Bricks "nav-menu" element in a flat node list at the given WP
   * menu term id. The exported elements reference the source site's menu id
   * (e.g. "8"), which does not exist on the clone, so we rewrite it to the menu
   * we just created.
   *
   * @param array<int, array<string, mixed>> $nodes
   * @return array<int, array<string, mixed>>
   */
  private function set_nav_menu_id_in_nodes(array $nodes, int $menu_id): array {
    if ($menu_id <= 0) {
      return $nodes;
    }

    foreach ($nodes as $index => $node) {
      if (!is_array($node) || ($node['name'] ?? '') !== 'nav-menu') {
        continue;
      }

      $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
      $settings['menu'] = (string) $menu_id;
      $nodes[$index]['settings'] = $settings;
    }

    return $nodes;
  }

  private function extract_bricks_admin_nonce(string $html): string {
    // Bricks localizes the admin nonce inside the `bricksData` object. Scope the
    // search to that object so we never grab a nonce from another script.
    $scope = $html;
    $pos = strpos($html, 'bricksData');
    if ($pos !== false) {
      $scope = substr($html, $pos, 4000);
    }

    // WordPress nonces are 10 hexadecimal-ish characters.
    if (preg_match('/"nonce"\s*:\s*"([a-zA-Z0-9]{10})"/', $scope, $m)) {
      return $m[1];
    }

    if (preg_match('/"nonce"\s*:\s*"([a-zA-Z0-9]{8,12})"/', $scope, $m)) {
      return $m[1];
    }

    return '';
  }

  private function build_bricks_settings_form_data(string $html, array $execute_roles): string {
    $pairs = [];

    if (class_exists('DOMDocument')) {
      $previous_errors = libxml_use_internal_errors(true);
      $document = new DOMDocument();
      // NOTE: DOMDocument::getElementById() is unreliable for HTML loaded via
      // loadHTML() (no DTD marks `id` as an ID attribute), so locate the Bricks
      // settings form through XPath instead.
      $loaded = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
      libxml_clear_errors();
      libxml_use_internal_errors($previous_errors);

      if ($loaded) {
        $xpath = new DOMXPath($document);
        $form_nodes = $xpath->query('//form[@id="bricks-settings"]');
        $form = ($form_nodes && $form_nodes->length > 0) ? $form_nodes->item(0) : null;
        if ($form instanceof DOMElement) {
          $pairs = $this->serialize_form_element_pairs($form);
        }
      }
    }

    // Fallback: even if we could not parse the existing form, still enable code
    // execution so the published site is immediately usable.
    $pairs = array_values(array_filter($pairs, static function ($pair): bool {
      $name = (string) ($pair[0] ?? '');
      return $name !== 'executeCodeEnabled' && $name !== 'executeCodeCapabilities[]';
    }));

    $pairs[] = ['executeCodeEnabled', 'on'];
    foreach ($execute_roles as $role) {
      $role = sanitize_key((string) $role);
      if ($role !== '') {
        $pairs[] = ['executeCodeCapabilities[]', $role];
      }
    }

    $encoded = [];
    foreach ($pairs as $pair) {
      $encoded[] = rawurlencode((string) $pair[0]) . '=' . rawurlencode((string) $pair[1]);
    }

    return implode('&', $encoded);
  }

  private function serialize_form_element_pairs(DOMElement $form): array {
    $pairs = [];
    foreach (['input', 'textarea', 'select'] as $tag_name) {
      foreach ($form->getElementsByTagName($tag_name) as $element) {
        if (!$element instanceof DOMElement) {
          continue;
        }

        $name = $element->getAttribute('name');
        if ($name === '' || $element->hasAttribute('disabled')) {
          continue;
        }

        if ($tag_name === 'textarea') {
          $pairs[] = [$name, $element->textContent];
          continue;
        }

        if ($tag_name === 'select') {
          $options = $element->getElementsByTagName('option');
          $selected = [];
          foreach ($options as $option) {
            if ($option instanceof DOMElement && $option->hasAttribute('selected')) {
              $selected[] = $option;
            }
          }
          if (empty($selected) && !$element->hasAttribute('multiple') && $options->length > 0 && $options->item(0) instanceof DOMElement) {
            $selected[] = $options->item(0);
          }
          foreach ($selected as $option) {
            $pairs[] = [$name, $option->hasAttribute('value') ? $option->getAttribute('value') : $option->textContent];
          }
          continue;
        }

        $type = strtolower($element->getAttribute('type') ?: 'text');
        if (in_array($type, ['submit', 'button', 'reset', 'file', 'image'], true)) {
          continue;
        }
        if (in_array($type, ['checkbox', 'radio'], true) && !$element->hasAttribute('checked')) {
          continue;
        }

        $pairs[] = [$name, $element->hasAttribute('value') ? $element->getAttribute('value') : 'on'];
      }
    }

    return $pairs;
  }

  /**
   * Load the Bricks builder page for a post and scrape the builder nonce
   * (`bricks-nonce-builder`) that Bricks localizes into the `bricksData` object.
   */
  private function fetch_bricks_builder_nonce(string $site_url, int $post_id, array $cookies, int $timeout): string {
    $builder_url = $site_url . '/?page_id=' . $post_id . '&bricks=run';
    $response = $this->remote_request($builder_url, [
      'method' => 'GET',
      'timeout' => max(20, min(120, $timeout)),
      'redirection' => 3,
      'cookies' => $cookies,
    ]);

    if (is_wp_error($response)) {
      error_log('[AISB] Bricks builder page fetch error for post ' . $post_id . ': ' . $response->get_error_message());
      return '';
    }

    $html = (string) ($response['body'] ?? '');
    if ($html === '') {
      return '';
    }

    // In Bricks' localized `bricksData` object the builder nonce sits directly
    // before the `ajaxUrl` key; anchor on that to avoid matching nested nonces.
    if (preg_match('/"nonce"\s*:\s*"([a-zA-Z0-9]+)"\s*,\s*"ajaxUrl"/', $html, $matches)) {
      return (string) $matches[1];
    }

    // Fallback: first standalone nonce field.
    if (preg_match('/"nonce"\s*:\s*"([a-zA-Z0-9]{8,12})"/', $html, $matches)) {
      return (string) $matches[1];
    }

    return '';
  }

  /**
   * Perform an HTTP request against the cloned InstaWP site with a DNS-over-HTTPS
   * + cURL `--resolve` fallback (the InstaWP hostnames frequently fail normal
   * DNS resolution from the publishing host).
   *
   * @return array{code:int, body:string, cookies:array}|WP_Error
   */
  private function remote_request(string $url, array $args = []) {
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    $timeout = (int) ($args['timeout'] ?? 25);

    $wp_args = [
      'method' => $method,
      'timeout' => max(10, min(120, $timeout)),
      'redirection' => (int) ($args['redirection'] ?? 0),
      'headers' => isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [],
      'cookies' => isset($args['cookies']) && is_array($args['cookies']) ? $args['cookies'] : [],
    ];
    if (array_key_exists('body', $args)) {
      $wp_args['body'] = $args['body'];
    }

    $response = wp_remote_request($url, $wp_args);
    if (!is_wp_error($response)) {
      return [
        'code' => (int) wp_remote_retrieve_response_code($response),
        'body' => (string) wp_remote_retrieve_body($response),
        'cookies' => wp_remote_retrieve_cookies($response),
      ];
    }

    if (!$this->should_use_resolve_fallback($response->get_error_message())) {
      return $response;
    }

    return $this->remote_request_via_curl_resolve($url, $wp_args, $timeout);
  }

  /**
   * cURL transport for {@see remote_request()} that resolves the host via
   * DNS-over-HTTPS and pins it with CURLOPT_RESOLVE. Handles request/response
   * cookies so an authenticated session can be carried across calls.
   *
   * @return array{code:int, body:string, cookies:array}|WP_Error
   */
  private function remote_request_via_curl_resolve(string $url, array $wp_args, int $timeout) {
    if (!function_exists('curl_init')) {
      return new WP_Error('aisb_instawp_missing_curl', 'The cURL extension is required for the InstaWP DNS fallback transport.', ['status' => 500]);
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
      return new WP_Error('aisb_instawp_invalid_url', 'The remote URL could not be parsed.', ['status' => 500]);
    }

    $host = (string) $parts['host'];
    $scheme = strtolower((string) $parts['scheme']);
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    $ip_address = $this->resolve_host_via_doh($host, $timeout);
    if (is_wp_error($ip_address)) {
      return $ip_address;
    }

    $headers = [];
    foreach ((array) ($wp_args['headers'] ?? []) as $key => $value) {
      $headers[] = $key . ': ' . $value;
    }

    // Serialise request cookies into a single Cookie header.
    $cookie_pairs = [];
    foreach ((array) ($wp_args['cookies'] ?? []) as $cookie) {
      if ($cookie instanceof WP_Http_Cookie) {
        $cookie_pairs[] = $cookie->name . '=' . $cookie->value;
      } elseif (is_array($cookie) && isset($cookie['name'])) {
        $cookie_pairs[] = $cookie['name'] . '=' . ($cookie['value'] ?? '');
      }
    }
    if (!empty($cookie_pairs)) {
      $headers[] = 'Cookie: ' . implode('; ', $cookie_pairs);
    }

    $method = strtoupper((string) ($wp_args['method'] ?? 'GET'));
    $redirection = (int) ($wp_args['redirection'] ?? 0);

    $handle = curl_init($url);
    $options = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER => true,
      CURLOPT_TIMEOUT => max(10, min(120, $timeout)),
      CURLOPT_FOLLOWLOCATION => $redirection > 0,
      CURLOPT_MAXREDIRS => max(0, $redirection),
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_USERAGENT => 'AI Sitemap Builder Publisher',
      CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $ip_address)],
    ];

    $this->apply_curl_ca_bundle($options);

    if ($method === 'POST') {
      $options[CURLOPT_POST] = true;
      $body = $wp_args['body'] ?? [];
      $options[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : (string) $body;
    } elseif ($method !== 'GET') {
      $options[CURLOPT_CUSTOMREQUEST] = $method;
      if (array_key_exists('body', $wp_args)) {
        $body = $wp_args['body'];
        $options[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : (string) $body;
      }
    }

    curl_setopt_array($handle, $options);

    $raw = curl_exec($handle);
    if ($raw === false) {
      $error = curl_error($handle);
      $errno = curl_errno($handle);
      curl_close($handle);
      return new WP_Error('aisb_instawp_curl_resolve_failed', 'cURL error ' . $errno . ': ' . $error, ['status' => 502]);
    }

    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $header_size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);

    $header_blob = substr((string) $raw, 0, $header_size);
    $response_body = substr((string) $raw, $header_size);

    return [
      'code' => $status,
      'body' => (string) $response_body,
      'cookies' => $this->parse_set_cookie_headers($header_blob, $host),
    ];
  }

  /**
   * Point cURL at WordPress' bundled CA certificate so TLS verification keeps
   * working even when the local PHP/system trust store is missing or broken
   * (e.g. Local by Flywheel dev environments), which otherwise fails with
   * "cURL error 60: SSL certificate problem". Verification stays ON.
   *
   * @param array<int, mixed> $options cURL option array (modified in place).
   */
  private function apply_curl_ca_bundle(array &$options): void {
    $options[CURLOPT_SSL_VERIFYPEER] = true;
    $options[CURLOPT_SSL_VERIFYHOST] = 2;

    $ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
    if (is_readable($ca_bundle)) {
      $options[CURLOPT_CAINFO] = $ca_bundle;
    }
  }

  /**
   * Build WP_Http_Cookie objects from the Set-Cookie headers of a cURL response
   * so the authenticated session can be reused on follow-up requests.
   *
   * @return WP_Http_Cookie[]
   */
  private function parse_set_cookie_headers(string $header_blob, string $host): array {
    $cookies = [];
    if (preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;\r\n]*)/im', $header_blob, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $name = trim((string) ($match[1] ?? ''));
        if ($name === '') continue;
        $cookies[] = new WP_Http_Cookie([
          'name' => $name,
          'value' => trim((string) ($match[2] ?? '')),
          'domain' => $host,
          'path' => '/',
        ]);
      }
    }
    return $cookies;
  }


  private function fetch_remote_pages(array $site, int $timeout) {
    $result = $this->call_xmlrpc(
      $site['xmlrpc_url'],
      $timeout,
      'wp.getPages',
      [self::XMLRPC_BLOG_ID, $site['username'], $site['password'], 200],
      'fetch remote pages'
    );
    if (is_wp_error($result)) {
      return $result;
    }

    $pages = [];
    foreach (is_array($result) ? $result : [] as $page) {
      if (!is_array($page)) continue;
      $slug = sanitize_title($page['wp_slug'] ?? '');
      if ($slug === '') continue;

      $custom_fields = $this->filter_remote_bricks_custom_fields(
        isset($page['custom_fields']) && is_array($page['custom_fields']) ? $page['custom_fields'] : []
      );

      $pages[$slug] = [
        'page_id' => (int) ($page['page_id'] ?? 0),
        'wp_slug' => $slug,
        'title' => (string) ($page['title'] ?? ''),
        'custom_fields' => $custom_fields,
        'meta_keys' => array_keys($custom_fields),
      ];
    }

    return $pages;
  }

  private function page_uses_bricks_export(array $page): bool {
    foreach (isset($page['sections']) && is_array($page['sections']) ? $page['sections'] : [] as $section) {
      if (!is_array($section)) continue;
      if (!empty($section['bricks_elements']) && is_array($section['bricks_elements'])) {
        return true;
      }
    }

    return false;
  }

  private function filter_remote_bricks_custom_fields(array $custom_fields): array {
    $allowed_keys = ['_bricks_page_content_2', '_bricks_data', '_bricks_page_header_2', '_bricks_page_footer_2', '_bricks_editor_mode'];
    $filtered = [];

    foreach ($custom_fields as $field) {
      if (!is_array($field)) continue;

      $key = (string) ($field['key'] ?? '');
      if (!in_array($key, $allowed_keys, true)) continue;

      $filtered[$key] = [
        'id' => (int) ($field['id'] ?? 0),
        'key' => $key,
        'value' => isset($field['value']) && is_scalar($field['value']) ? (string) $field['value'] : '',
      ];
    }

    return $filtered;
  }

  private function build_bricks_custom_fields(array $payload, array $existing_fields): array {
    $body = isset($payload['content']) && is_array($payload['content']) ? $payload['content'] : [];
    $header = isset($payload['header']) && is_array($payload['header']) ? $payload['header'] : [];
    $footer = isset($payload['footer']) && is_array($payload['footer']) ? $payload['footer'] : [];

    $values = [
      '_bricks_page_content_2' => $body,
      '_bricks_data' => $body,
      '_bricks_editor_mode' => 'bricks',
    ];

    if (!empty($header) || isset($existing_fields['_bricks_page_header_2'])) {
      $values['_bricks_page_header_2'] = $header;
    }

    if (!empty($footer) || isset($existing_fields['_bricks_page_footer_2'])) {
      $values['_bricks_page_footer_2'] = $footer;
    }

    $fields = [];
    foreach ($values as $key => $value) {
      $existing = $existing_fields[$key] ?? null;
      if (is_array($existing) && !empty($existing['id'])) {
        $fields[] = [
          'id' => (int) $existing['id'],
          'key' => $key,
          'value' => $value,
        ];
        continue;
      }

      $fields[] = [
        'key' => $key,
        'value' => $value,
      ];
    }

    return $fields;
  }

  private function call_xmlrpc(string $xmlrpc_url, int $timeout, string $method, array $params, string $context) {
    $last_error_message = 'Unknown XML-RPC error.';
    $last_error_code = 0;
    $max_wait_seconds = max(90, min(180, $timeout + 90));
    $deadline = time() + $max_wait_seconds;
    $attempt = 0;

    while (time() <= $deadline) {
      $attempt++;
      if (!class_exists('IXR_Request')) {
        require_once ABSPATH . WPINC . '/class-IXR.php';
      }

      $request = new IXR_Request($method, $params);
      $response = wp_remote_post(trim($xmlrpc_url), [
        'timeout' => max(10, min(120, $timeout)),
        'headers' => [
          'Content-Type' => 'text/xml',
        ],
        'user-agent' => 'AI Sitemap Builder XML-RPC Publisher',
        'body' => $request->getXml(),
      ]);

      if (is_wp_error($response) && $this->should_use_resolve_fallback($response->get_error_message())) {
        $resolved_response = $this->perform_xmlrpc_request_via_curl_resolve(
          trim($xmlrpc_url),
          $request->getXml(),
          $timeout
        );
        if (!is_wp_error($resolved_response)) {
          $response = $resolved_response;
        } else {
          error_log('[AISB] XML-RPC DNS fallback failed: ' . $resolved_response->get_error_message());
          $response = $resolved_response;
        }
      }

      if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
        $message = new IXR_Message(wp_remote_retrieve_body($response));
        if ($message->parse()) {
          if ($message->messageType === 'fault') {
            $last_error_code = (int) $message->faultCode;
            $last_error_message = (string) $message->faultString;
          } else {
            return $message->params[0] ?? null;
          }
        } else {
          $last_error_code = -32700;
          $last_error_message = 'parse error. not well formed';
        }
      } else {
        if (is_wp_error($response)) {
          $last_error_code = -32300;
          $last_error_message = 'transport error: ' . $response->get_error_code() . ' ' . $response->get_error_message();
        } else {
          $last_error_code = -32301;
          $last_error_message = 'transport error - HTTP status code was not 200 (' . (int) wp_remote_retrieve_response_code($response) . ')';
        }
      }

      error_log(
        sprintf(
          '[AISB] XML-RPC %s failed attempt %d: #%d %s',
          $method,
          $attempt,
          $last_error_code,
          $last_error_message
        )
      );

      if (!$this->should_retry_xmlrpc_error($last_error_code, $last_error_message, $attempt, PHP_INT_MAX)) {
        break;
      }

      $lower_error_message = strtolower($last_error_message);
      if (str_contains($lower_error_message, 'http status code was not 200 (429)') || str_contains($lower_error_message, 'too many requests')) {
        $sleep = 10000000;
      } elseif (str_contains($lower_error_message, 'incorrect username or password')) {
        $sleep = 5000000;
      } elseif (str_contains($lower_error_message, 'could not resolve host') || str_contains($lower_error_message, 'dns-over-https') || str_contains($lower_error_message, 'instawp_dns_failed')) {
        $sleep = 5000000;
      } else {
        $sleep = min(3000000, 750000 * $attempt);
      }

      usleep($sleep);
    }

    return new WP_Error(
      'aisb_instawp_xmlrpc_failed',
      sprintf('Failed to %s on the new InstaWP site: %s', $context, $last_error_message),
      ['status' => 502, 'error_code' => $last_error_code]
    );
  }

  private function should_retry_xmlrpc_error(int $error_code, string $error_message, int $attempt, int $max_attempts): bool {
    if ($attempt >= $max_attempts) {
      return false;
    }

    $message = strtolower($error_message);
    if (in_array($error_code, [401, 403], true)) {
      return str_contains($message, 'incorrect username or password');
    }

    if ($error_code < 0) {
      return true;
    }

    return str_contains($message, 'transport error')
      || str_contains($message, 'not well formed')
      || str_contains($message, 'timed out')
      || str_contains($message, 'temporarily unavailable');
  }

  private function should_use_resolve_fallback(string $error_message): bool {
    $message = strtolower($error_message);
    return str_contains($message, 'could not resolve host')
      || str_contains($message, 'operation timed out')
      || str_contains($message, 'timed out after')
      || str_contains($message, 'connection timed out');
  }

  private function perform_xmlrpc_request_via_curl_resolve(string $xmlrpc_url, string $xml_body, int $timeout) {
    if (!function_exists('curl_init')) {
      return new WP_Error('aisb_instawp_missing_curl', 'The cURL extension is required for the InstaWP DNS fallback transport.', ['status' => 500]);
    }

    $parts = wp_parse_url($xmlrpc_url);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
      return new WP_Error('aisb_instawp_invalid_xmlrpc_url', 'The remote XML-RPC URL could not be parsed.', ['status' => 500]);
    }

    $host = (string) $parts['host'];
    $scheme = strtolower((string) $parts['scheme']);
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    $ip_address = $this->resolve_host_via_doh($host, $timeout);
    if (is_wp_error($ip_address)) {
      error_log('[AISB] DNS-over-HTTPS lookup failed for ' . $host . ': ' . $ip_address->get_error_message());
      return $ip_address;
    }

    error_log('[AISB] XML-RPC DNS fallback resolved ' . $host . ' -> ' . $ip_address);

    $handle = curl_init($xmlrpc_url);
    $options = [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $xml_body,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => max(10, min(120, $timeout)),
      CURLOPT_HTTPHEADER => [
        'Content-Type: text/xml',
        'User-Agent: AI Sitemap Builder XML-RPC Publisher',
      ],
      CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $ip_address)],
    ];

    $this->apply_curl_ca_bundle($options);

    curl_setopt_array($handle, $options);

    $body = curl_exec($handle);
    if ($body === false) {
      $error = curl_error($handle);
      $errno = curl_errno($handle);
      curl_close($handle);
      error_log('[AISB] XML-RPC DNS fallback cURL error #' . $errno . ': ' . $error);
      return new WP_Error('aisb_instawp_curl_resolve_failed', 'cURL error ' . $errno . ': ' . $error, ['status' => 502]);
    }

    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    error_log('[AISB] XML-RPC DNS fallback HTTP status ' . $status . ' for ' . $host);

    return [
      'response' => [
        'code' => $status,
      ],
      'body' => (string) $body,
    ];
  }

  private function resolve_host_via_doh(string $host, int $timeout) {
    static $cache = [];

    $host = trim($host);
    if ($host === '') {
      return new WP_Error('aisb_instawp_missing_host', 'The remote host is empty.', ['status' => 500]);
    }

    if (isset($cache[$host])) {
      return $cache[$host];
    }

    $response = wp_remote_get('https://dns.google/resolve?name=' . rawurlencode($host) . '&type=A', [
      'timeout' => max(10, min(120, $timeout)),
      'headers' => [
        'Accept' => 'application/json',
      ],
    ]);

    if (is_wp_error($response)) {
      error_log('[AISB] DNS-over-HTTPS request failed for ' . $host . ': ' . $response->get_error_message());
      return new WP_Error('aisb_instawp_dns_failed', $response->get_error_message(), ['status' => 502]);
    }

    $decoded = json_decode(wp_remote_retrieve_body($response), true);
    $answers = isset($decoded['Answer']) && is_array($decoded['Answer']) ? $decoded['Answer'] : [];
    foreach ($answers as $answer) {
      if (!is_array($answer)) continue;
      if ((int) ($answer['type'] ?? 0) !== 1) continue;
      $ip_address = trim((string) ($answer['data'] ?? ''));
      if ($ip_address !== '') {
        $cache[$host] = $ip_address;
        return $ip_address;
      }
    }

    error_log('[AISB] DNS-over-HTTPS returned no A record for ' . $host . ': ' . wp_remote_retrieve_body($response));

    return new WP_Error('aisb_instawp_dns_failed', 'Could not resolve the InstaWP host via DNS-over-HTTPS.', ['status' => 502]);
  }

  private function create_xmlrpc_client(string $xmlrpc_url, int $timeout): WP_HTTP_IXR_Client {
    if (!class_exists('IXR_Client')) {
      require_once ABSPATH . WPINC . '/class-IXR.php';
    }
    if (!class_exists('WP_HTTP_IXR_Client')) {
      require_once ABSPATH . WPINC . '/class-wp-http-ixr-client.php';
    }

    $client = new WP_HTTP_IXR_Client($xmlrpc_url, false, false, max(10, min(120, $timeout)));
    $client->useragent = 'AI Sitemap Builder XML-RPC Publisher';
    return $client;
  }

  private function extract_wordpress_site_config(array $payload) {
    $site_url = untrailingslashit($this->extract_first_value($payload, ['wp_url']));
    if ($site_url === '') {
      $site_url = untrailingslashit($this->extract_first_value($payload, ['site_url', 'url']));
    }

    $username = $this->extract_first_value($payload, ['wp_username']);
    $password = $this->extract_first_value($payload, ['wp_password']);
    $admin_url = untrailingslashit($this->extract_first_value($payload, ['wp_admin_url', 'admin_url']));

    if ($site_url === '' || $username === '' || $password === '') {
      return new WP_Error(
        'aisb_instawp_missing_wp_credentials',
        'The InstaWP response did not include the WordPress site URL and login credentials needed to import pages.',
        ['status' => 502]
      );
    }

    return [
      'site_url' => $site_url,
      'admin_url' => $admin_url !== '' ? $admin_url : trailingslashit($site_url) . 'wp-admin/',
      'xmlrpc_url' => trailingslashit($site_url) . 'xmlrpc.php',
      'username' => $username,
      'password' => $password,
    ];
  }

  private function update_remote_blog_title(array $site, string $blog_title, int $timeout): bool {
    $blog_title = sanitize_text_field($blog_title);
    if ($blog_title === '') {
      return false;
    }

    $result = $this->call_xmlrpc(
      $site['xmlrpc_url'],
      $timeout,
      'wp.setOptions',
      [self::XMLRPC_BLOG_ID, $site['username'], $site['password'], ['blog_title' => $blog_title]],
      'update the site title'
    );

    if (is_wp_error($result)) {
      error_log('[AISB] Failed to update remote blog title: ' . $result->get_error_message());
      return false;
    }

    return true;
  }

  private function set_remote_front_page(array $site, int $page_id): bool {
    $login = $this->remote_wordpress_login($site);
    if (is_wp_error($login)) {
      error_log('[AISB] Remote front-page update skipped: ' . $login->get_error_message());
      return false;
    }

    $reading_url = trailingslashit($site['site_url']) . 'wp-admin/options-reading.php';
    $reading_response = $this->remote_request($reading_url, [
      'method' => 'GET',
      'timeout' => 20,
      'redirection' => 5,
      'cookies' => $login['cookies'],
    ]);

    if (is_wp_error($reading_response)) {
      error_log('[AISB] Failed to open remote reading settings: ' . $reading_response->get_error_message());
      return false;
    }

    $reading_body = (string) ($reading_response['body'] ?? '');
    $nonce = $this->extract_wp_admin_nonce($reading_body);
    if ($nonce === '') {
      error_log('[AISB] Could not find the options-reading nonce on the remote site.');
      return false;
    }

    $options_response = $this->remote_request(trailingslashit($site['site_url']) . 'wp-admin/options.php', [
      'method' => 'POST',
      'timeout' => 20,
      'redirection' => 0,
      'cookies' => $login['cookies'],
      'headers' => [
        'Referer' => $reading_url,
      ],
      'body' => [
        'option_page' => 'reading',
        'action' => 'update',
        '_wpnonce' => $nonce,
        '_wp_http_referer' => '/wp-admin/options-reading.php',
        'show_on_front' => 'page',
        'page_on_front' => (string) $page_id,
        'page_for_posts' => '0',
      ],
    ]);

    if (is_wp_error($options_response)) {
      error_log('[AISB] Failed to update remote front-page settings: ' . $options_response->get_error_message());
      return false;
    }

    $status = (int) ($options_response['code'] ?? 0);
    return $status === 200 || $status === 302;
  }

  private function remote_wordpress_login(array $site) {
    $site_url = untrailingslashit((string) ($site['site_url'] ?? ''));
    if ($site_url === '') {
      return new WP_Error('aisb_instawp_missing_site_url', 'The remote site URL is missing.', ['status' => 500]);
    }

    $host = (string) wp_parse_url($site_url, PHP_URL_HOST);
    $request_cookies = [];
    if ($host !== '') {
      $request_cookies[] = new WP_Http_Cookie([
        'name' => 'wordpress_test_cookie',
        'value' => 'WP Cookie check',
        'domain' => $host,
        'path' => '/',
      ]);
    }

    $response = $this->remote_request($site_url . '/wp-login.php', [
      'method' => 'POST',
      'timeout' => 20,
      'redirection' => 0,
      'cookies' => $request_cookies,
      'body' => [
        'log' => (string) ($site['username'] ?? ''),
        'pwd' => (string) ($site['password'] ?? ''),
        'wp-submit' => 'Log In',
        'redirect_to' => trailingslashit($site_url) . 'wp-admin/',
        'testcookie' => '1',
      ],
    ]);

    if (is_wp_error($response)) {
      return new WP_Error('aisb_instawp_login_failed', $response->get_error_message(), ['status' => 502]);
    }

    $status = (int) ($response['code'] ?? 0);
    $cookies = array_merge($request_cookies, (array) ($response['cookies'] ?? []));
    if (($status < 300 || $status >= 400) && stripos((string) ($response['body'] ?? ''), 'login_error') !== false) {
      return new WP_Error('aisb_instawp_login_failed', 'Remote WordPress login failed.', ['status' => 502]);
    }

    if (empty($cookies)) {
      return new WP_Error('aisb_instawp_login_failed', 'Remote WordPress login did not return any cookies.', ['status' => 502]);
    }

    return ['cookies' => $cookies];
  }

  /**
   * Scrape the value of a named <input> field from admin HTML. Handles both
   * attribute orders (name before value and value before name).
   */
  private function extract_input_value(string $html, string $name): string {
    $escaped = preg_quote($name, '/');
    if (preg_match('/<input\b[^>]*\bname=["\']' . $escaped . '["\'][^>]*\bvalue=["\']([^"\']*)["\']/i', $html, $matches)) {
      return $matches[1];
    }
    if (preg_match('/<input\b[^>]*\bvalue=["\']([^"\']*)["\'][^>]*\bname=["\']' . $escaped . '["\']/i', $html, $matches)) {
      return $matches[1];
    }
    return '';
  }

  private function extract_wp_admin_nonce(string $html): string {
    if (preg_match('/name=["\']_wpnonce["\']\s+value=["\']([^"\']+)["\']/i', $html, $matches)) {
      return (string) ($matches[1] ?? '');
    }

    if (preg_match('/id=["\']_wpnonce["\']\s+name=["\']_wpnonce["\']\s+value=["\']([^"\']+)["\']/i', $html, $matches)) {
      return (string) ($matches[1] ?? '');
    }

    return '';
  }

  private function build_remote_page_html(
    array $page,
    array $all_pages,
    array $style_guide,
    array $page_section_images,
    string $front_slug,
    string $site_url,
    string $project_name
  ): string {
    $body_sections = [];
    $footer_sections = [];

    foreach (isset($page['sections']) && is_array($page['sections']) ? array_values($page['sections']) : [] as $section_index => $section) {
      if (!is_array($section)) continue;

      $section_html = $this->build_remote_section_html(
        $section,
        (int) $section_index,
        isset($page_section_images[$section_index]) && is_array($page_section_images[$section_index])
          ? $page_section_images[$section_index]
          : [],
        $style_guide
      );
      if ($section_html === '') continue;

      $type = strtolower((string) ($section['type'] ?? ''));
      if ($type === 'footer') {
        $footer_sections[] = $section_html;
        continue;
      }
      if ($type === 'header') {
        continue;
      }

      $body_sections[] = $section_html;
    }

    if (empty($body_sections)) {
      $body_sections[] = '<section class="aisb-section aisb-section--generic"><div class="aisb-section__inner"><h1>'
        . esc_html((string) ($page['title'] ?? 'Published Page'))
        . '</h1><p>Published from AI Sitemap Builder.</p></div></section>';
    }

    $html = [];
    $html[] = '<div class="aisb-publish">';
    $html[] = '<style>' . $this->build_remote_page_css($style_guide) . '</style>';
    $html[] = $this->build_remote_navigation_html(
      $all_pages,
      $site_url,
      $front_slug,
      (string) ($page['slug'] ?? ''),
      $project_name,
      (string) ($style_guide['logoUrl'] ?? '')
    );
    $html[] = '<main class="aisb-main">' . implode("\n", $body_sections) . '</main>';
    $html[] = !empty($footer_sections)
      ? '<footer class="aisb-footer aisb-footer--custom">' . implode("\n", $footer_sections) . '</footer>'
      : $this->build_remote_footer_html($all_pages, $site_url, $project_name);
    $html[] = '</div>';

    return implode("\n", $html);
  }

  private function build_remote_page_css(array $style_guide): string {
    $primary = $this->find_style_guide_colour($style_guide, 'Primary') ?: '#1f66ad';
    $accent = $this->find_style_guide_colour($style_guide, 'Accent') ?: '#2b69b6';
    $dark = $this->find_style_guide_colour($style_guide, 'Dark') ?: '#10233a';
    $light = $this->find_style_guide_colour($style_guide, 'Light') ?: '#f4f6fb';
    $neutral = $this->find_style_guide_colour($style_guide, 'Neutral') ?: '#eef1f5';
    $body_background = trim((string) ($style_guide['pageBackground'] ?? $style_guide['bodyBackground'] ?? $style_guide['backgroundColor'] ?? $light));
    $heading_font = $this->sanitize_remote_font_family((string) ($style_guide['headingFont'] ?? 'Georgia'));
    $body_font = $this->sanitize_remote_font_family((string) ($style_guide['bodyFont'] ?? 'Arial'));

    return ':root{'
      . '--aisb-primary:' . $primary . ';'
      . '--aisb-accent:' . $accent . ';'
      . '--aisb-dark:' . $dark . ';'
      . '--aisb-light:' . $light . ';'
      . '--aisb-neutral:' . $neutral . ';'
      . '--aisb-body-bg:' . $body_background . ';'
      . '--aisb-heading-font:' . $heading_font . ';'
      . '--aisb-body-font:' . $body_font . ';'
      . '}'
      . '.aisb-publish{background:var(--aisb-body-bg);color:var(--aisb-dark);font-family:var(--aisb-body-font),sans-serif;padding:0;margin:0;}'
      . '.aisb-header,.aisb-footer{padding:24px 20px;}'
      . '.aisb-header{background:linear-gradient(135deg,var(--aisb-dark),var(--aisb-primary));color:#fff;}'
      . '.aisb-header__inner,.aisb-footer__inner,.aisb-section__inner{max-width:1120px;margin:0 auto;}'
      . '.aisb-brand{display:flex;align-items:center;gap:14px;color:#fff;text-decoration:none;font-weight:700;letter-spacing:.02em;}'
      . '.aisb-brand__logo{width:52px;height:52px;object-fit:contain;border-radius:14px;background:rgba(255,255,255,.12);padding:8px;}'
      . '.aisb-nav{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;}'
      . '.aisb-nav__link{display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:rgba(255,255,255,.12);color:#fff;text-decoration:none;font-size:14px;}'
      . '.aisb-nav__link.is-active{background:#fff;color:var(--aisb-dark);}'
      . '.aisb-main{padding:32px 20px 56px;}'
      . '.aisb-section{padding:24px 0;}'
      . '.aisb-section__inner{background:#fff;border-radius:28px;padding:28px;box-shadow:0 18px 45px rgba(16,35,58,.08);}'
      . '.aisb-section--hero .aisb-section__inner{padding:40px 32px;background:linear-gradient(135deg,rgba(31,102,173,.14),rgba(43,105,182,.06));}'
      . '.aisb-section h1,.aisb-section h2,.aisb-section h3{font-family:var(--aisb-heading-font),serif;line-height:1.12;margin:0 0 16px;}'
      . '.aisb-section h1{font-size:clamp(2.3rem,4vw,4rem);}'
      . '.aisb-section h2{font-size:clamp(1.8rem,3vw,2.8rem);}'
      . '.aisb-section h3{font-size:1.35rem;}'
      . '.aisb-section p,.aisb-section li{font-size:1rem;line-height:1.75;color:rgba(16,35,58,.88);}'
      . '.aisb-richtext > *:first-child{margin-top:0;}.aisb-richtext > *:last-child{margin-bottom:0;}'
      . '.aisb-gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:24px;}'
      . '.aisb-gallery img{display:block;width:100%;height:240px;object-fit:cover;border-radius:20px;background:var(--aisb-neutral);}'
      . '.aisb-footer{padding-top:0;}.aisb-footer__inner{display:flex;flex-wrap:wrap;justify-content:space-between;gap:16px;color:rgba(16,35,58,.75);font-size:14px;}'
      . '.aisb-footer__links{display:flex;flex-wrap:wrap;gap:12px;}.aisb-footer__links a{color:var(--aisb-primary);text-decoration:none;}'
      . '@media (max-width: 720px){.aisb-section__inner{padding:22px;}.aisb-brand{align-items:flex-start;}.aisb-brand__logo{width:44px;height:44px;}}';
  }

  private function build_remote_navigation_html(
    array $all_pages,
    string $site_url,
    string $front_slug,
    string $current_slug,
    string $project_name,
    string $logo_url
  ): string {
    $brand_name = $project_name !== '' ? $project_name : 'Published Site';
    $home_url = $this->build_remote_page_url($site_url, $front_slug !== '' ? $front_slug : $current_slug);
    $logo_html = $logo_url !== ''
      ? '<img class="aisb-brand__logo" src="' . esc_url($logo_url, ['http', 'https', 'data']) . '" alt="' . esc_attr($brand_name) . '">'
      : '';

    $links = [];
    foreach ($all_pages as $page) {
      if (!is_array($page)) continue;
      $slug = sanitize_title($page['slug'] ?? '');
      if ($slug === '') continue;
      $links[] = '<a class="aisb-nav__link' . ($slug === $current_slug ? ' is-active' : '') . '" href="'
        . esc_url($this->build_remote_page_url($site_url, $slug))
        . '">' . esc_html((string) ($page['title'] ?? ucfirst(str_replace('-', ' ', $slug)))) . '</a>';
    }

    return '<header class="aisb-header"><div class="aisb-header__inner"><a class="aisb-brand" href="'
      . esc_url($home_url)
      . '">' . $logo_html . '<span>' . esc_html($brand_name) . '</span></a><nav class="aisb-nav">'
      . implode('', $links) . '</nav></div></header>';
  }

  private function build_remote_footer_html(array $all_pages, string $site_url, string $project_name): string {
    $links = [];
    foreach ($all_pages as $page) {
      if (!is_array($page)) continue;
      $slug = sanitize_title($page['slug'] ?? '');
      if ($slug === '') continue;

      $links[] = '<a href="' . esc_url($this->build_remote_page_url($site_url, $slug)) . '">'
        . esc_html((string) ($page['title'] ?? ucfirst(str_replace('-', ' ', $slug)))) . '</a>';
    }

    $name = $project_name !== '' ? $project_name : 'Published Site';

    return '<footer class="aisb-footer"><div class="aisb-footer__inner"><span>'
      . esc_html($name) . ' is powered by AI Sitemap Builder.</span><span class="aisb-footer__links">'
      . implode('', $links) . '</span></div></footer>';
  }

  private function build_remote_section_html(array $section, int $section_index, array $fallback_images, array $style_guide): string {
    $type = sanitize_html_class((string) ($section['type'] ?? 'generic'));
    $texts = $this->extract_remote_section_texts($section);
    $images = $this->extract_remote_section_images($section, $fallback_images);

    if (empty($texts) && empty($images)) {
      return '';
    }

    $parts = [];
    foreach ($texts as $text_index => $text) {
      $formatted = $this->format_remote_text_block((string) $text, (int) $text_index, $type);
      if ($formatted !== '') {
        $parts[] = $formatted;
      }
    }

    if (!empty($images)) {
      $parts[] = '<div class="aisb-gallery">' . implode('', array_map(static function ($url): string {
        return '<img src="' . esc_url($url, ['http', 'https', 'data']) . '" alt="">';
      }, $images)) . '</div>';
    }

    $background = $this->resolve_section_background($section, $section_index, $style_guide);

    return '<section class="aisb-section aisb-section--' . esc_attr($type) . '"'
      . ($background !== '' ? ' style="' . esc_attr($background) . '"' : '')
      . '><div class="aisb-section__inner">' . implode("\n", $parts) . '</div></section>';
  }

  private function extract_remote_section_texts(array $section): array {
    $texts = [];
    $raw_texts = isset($section['content']['texts']) && is_array($section['content']['texts'])
      ? $section['content']['texts']
      : [];

    foreach ($raw_texts as $entry) {
      $value = '';
      if (is_scalar($entry)) {
        $value = trim((string) $entry);
      } elseif (is_array($entry)) {
        foreach (['html', 'text', 'value', 'content', 'label', 'title', 'description'] as $key) {
          if (isset($entry[$key]) && is_scalar($entry[$key]) && trim((string) $entry[$key]) !== '') {
            $value = trim((string) $entry[$key]);
            break;
          }
        }
      }

      if ($value !== '' && trim(wp_strip_all_tags($value)) !== '') {
        $texts[] = $value;
      }
    }

    if (empty($texts) && !empty($section['patch']) && is_array($section['patch'])) {
      foreach ($section['patch'] as $patch) {
        if (!is_array($patch)) continue;
        if (($patch['type'] ?? '') !== 'text') continue;
        if (!isset($patch['text']) || !is_scalar($patch['text'])) continue;

        $value = trim((string) $patch['text']);
        if ($value !== '') {
          $texts[] = $value;
        }
      }
    }

    return array_values(array_unique($texts));
  }

  private function extract_remote_section_images(array $section, array $fallback_images): array {
    $images = [];
    $content_images = isset($section['content']['images']) && is_array($section['content']['images'])
      ? $section['content']['images']
      : [];

    foreach ($content_images as $image) {
      $url = $this->extract_remote_image_url($image);
      if ($url !== '') {
        $images[$url] = $url;
      }
    }

    foreach ($fallback_images as $image) {
      $url = $this->extract_remote_image_url($image);
      if ($url !== '') {
        $images[$url] = $url;
      }
    }

    if (!empty($section['patch']) && is_array($section['patch'])) {
      foreach ($section['patch'] as $patch) {
        if (!is_array($patch) || ($patch['type'] ?? '') !== 'img') continue;
        $url = $this->extract_remote_image_url($patch['src'] ?? '');
        if ($url !== '') {
          $images[$url] = $url;
        }
      }
    }

    return array_values($images);
  }

  private function extract_remote_image_url($image): string {
    $url = '';

    if (is_scalar($image)) {
      $url = trim((string) $image);
    } elseif (is_array($image)) {
      foreach (['full', 'url', 'src', 'thumb'] as $key) {
        if (isset($image[$key]) && is_scalar($image[$key]) && trim((string) $image[$key]) !== '') {
          $url = trim((string) $image[$key]);
          break;
        }
      }
    }

    return $url !== '' ? $this->convert_local_url_to_data_uri($url) : '';
  }

  private function format_remote_text_block(string $text, int $text_index, string $section_type): string {
    $text = trim($text);
    if ($text === '') {
      return '';
    }

    if ($this->string_is_html_fragment($text)) {
      return '<div class="aisb-richtext">' . wp_kses_post($text) . '</div>';
    }

    $tag = 'p';
    $plain_text = trim(wp_strip_all_tags($text));
    if ($text_index === 0) {
      $tag = $section_type === 'hero' ? 'h1' : 'h2';
    } elseif (strlen($plain_text) <= 90 && !str_contains($plain_text, '.')) {
      $tag = 'h3';
    }

    return '<' . $tag . '>' . nl2br(esc_html($plain_text)) . '</' . $tag . '>';
  }

  private function string_is_html_fragment(string $text): bool {
    return $text !== strip_tags($text) || (bool) preg_match('/<\s*(p|h[1-6]|ul|ol|li|div|blockquote|strong|em|br)\b/i', $text);
  }

  private function build_remote_page_url(string $site_url, string $slug): string {
    $slug = trim($slug, '/');
    if ($slug === '') {
      return trailingslashit($site_url);
    }

    return trailingslashit($site_url) . trailingslashit($slug);
  }

  private function resolve_section_background(array $section, int $section_index, array $style_guide): string {
    $background = trim((string) ($section['bg_color'] ?? ''));
    if ($background === '' && isset($section['bg_index']) && $section['bg_index'] !== null) {
      $background = (int) $section['bg_index'] % 2 === 0
        ? trim((string) ($style_guide['sectionBg1'] ?? ''))
        : trim((string) ($style_guide['sectionBg2'] ?? ''));
    }
    if ($background === '') {
      $background = $section_index % 2 === 0
        ? trim((string) ($style_guide['sectionBg1'] ?? ''))
        : trim((string) ($style_guide['sectionBg2'] ?? ''));
    }

    return $background !== '' ? 'background:' . preg_replace('/[^#(),.%\sA-Za-z0-9-]/', '', $background) . ';' : '';
  }

  private function sanitize_remote_font_family(string $font): string {
    $font = trim($font);
    if ($font === '') {
      return 'sans-serif';
    }

    $font = preg_replace('/[^A-Za-z0-9\s,\-\"]/', '', $font);
    return $font !== '' ? '"' . trim($font, '"') . '"' : 'sans-serif';
  }

  private function find_style_guide_colour(array $style_guide, string $name): string {
    $colours = isset($style_guide['colours']) && is_array($style_guide['colours']) ? $style_guide['colours'] : [];
    foreach ($colours as $colour) {
      if (!is_array($colour)) continue;
      if ((string) ($colour['name'] ?? '') !== $name) continue;
      $hex = trim((string) ($colour['hex'] ?? ''));
      if ($hex !== '') {
        return $hex;
      }
    }

    return '';
  }

  private function resolve_front_page_slug(array $pages): string {
    $preferred = ['home', 'homepage', 'front-page', 'index'];
    foreach ($preferred as $slug) {
      foreach ($pages as $page) {
        if (!is_array($page)) continue;
        if ((string) ($page['slug'] ?? '') === $slug) {
          return $slug;
        }
      }
    }

    $first = reset($pages);
    return is_array($first) ? (string) ($first['slug'] ?? '') : '';
  }

  private function prepare_export_payload_for_target(array $page, array $target_meta_keys, array $runtime_payload, array $page_section_images = []) {
    $has_separate_chrome = in_array('_bricks_page_header_2', $target_meta_keys, true)
      || in_array('_bricks_page_footer_2', $target_meta_keys, true);

    $logo_url = '';
    if (isset($runtime_payload['style_guide']['logoUrl'])) {
      $logo_url = trim((string) $runtime_payload['style_guide']['logoUrl']);
    }

    $style_guide = isset($runtime_payload['style_guide']) && is_array($runtime_payload['style_guide'])
      ? $runtime_payload['style_guide']
      : [];

    $body = [];
    $header = [];
    $footer = [];

    $sections = isset($page['sections']) && is_array($page['sections']) ? array_values($page['sections']) : [];
    foreach ($sections as $section_index => $section) {
      if (!is_array($section)) continue;

      $section_image_urls = isset($page_section_images[(int) $section_index]) && is_array($page_section_images[(int) $section_index])
        ? $page_section_images[(int) $section_index]
        : [];

      $section_bg_css = $this->resolve_section_background($section, (int) $section_index, $style_guide);
      $section_bg = $section_bg_css !== ''
        ? trim((string) preg_replace('/^background\s*:\s*/i', '', rtrim($section_bg_css, "; \t\n\r")))
        : '';

      $compiled = $this->compile_export_section($section, (int) $section_index, $section_image_urls, $logo_url, $section_bg, $style_guide);
      if (empty($compiled)) continue;

      $type = strtolower((string) ($section['type'] ?? ''));
      if ($has_separate_chrome && $type === 'header') {
        $header = array_merge($header, $compiled);
        continue;
      }
      if ($has_separate_chrome && $type === 'footer') {
        $footer = array_merge($footer, $compiled);
        continue;
      }

      $body = array_merge($body, $compiled);
    }

    $body[] = $this->build_runtime_code_element($runtime_payload);

    return [
      'content' => $body,
      'header' => $header,
      'footer' => $footer,
    ];
  }

  private function compile_export_section(array $section, int $section_index, array $section_image_urls = [], string $logo_url = '', string $section_bg = '', array $style_guide = []): array {
    // Gebruik de originele Bricks-structuur (vóór Figma-flatten) indien beschikbaar,
    // zodat accordion-elementen (bijv. FAQ) intact blijven op de gepubliceerde site.
    $source_elements = isset($section['bricks_elements_bricks']) && is_array($section['bricks_elements_bricks'])
      ? $section['bricks_elements_bricks']
      : ($section['bricks_elements'] ?? []);
    $elements = array_values(array_filter((array) $source_elements, 'is_array'));
    if (empty($elements)) {
      return [];
    }

    $elements = $this->bake_border_radius_patches($elements, $section);

    $rekeyed = $this->re_id_bricks_nodes($elements);
    $rekeyed = $this->force_root_parent_zero($rekeyed);
    $rekeyed = $this->tag_runtime_section_root($rekeyed, $section_index);
    $rekeyed = $this->neutralize_accordion_query_loops($rekeyed);
    $rekeyed = $this->bake_section_images($rekeyed, $section_image_urls);
    $rekeyed = $this->bake_logo_assets($rekeyed, $logo_url);
    $rekeyed = $this->bake_section_background($rekeyed, $section_bg);
    $rekeyed = $this->bake_styleguide_typography($rekeyed, $section, $style_guide);

    $type = strtolower((string) ($section['type'] ?? ''));
    if (!in_array($type, ['header', 'footer'], true)) {
      $rekeyed = $this->apply_section_padding($rekeyed);
    }

    return $rekeyed;
  }

  /**
   * Bake fonts and imported text sizes into native Bricks typography settings.
   *
   * The preview/runtime CSS can make the frontend look correct, but the Bricks
   * Builder only keeps typography when it is present in each element's
   * `_typography` settings. We therefore write the style guide fonts and any
   * imported/computed font sizes directly onto text-like nodes.
   *
   * @param array<int, array<string, mixed>> $nodes
   * @return array<int, array<string, mixed>>
   */
  private function bake_styleguide_typography(array $nodes, array $section, array $style_guide): array {
    $heading_font = $this->normalise_bricks_font_family((string) ($style_guide['headingFont'] ?? ''));
    $body_font = $this->normalise_bricks_font_family((string) ($style_guide['bodyFont'] ?? ''));

    $text_styles = [];
    if (isset($section['content']['text_styles']) && is_array($section['content']['text_styles'])) {
      $text_styles = array_values(array_filter($section['content']['text_styles'], 'is_array'));
    }

    $used_styles = [];
    $style_cursor = 0;

    foreach ($nodes as $index => $node) {
      if (!is_array($node) || !$this->is_typography_node($node)) {
        continue;
      }

      $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
      $current_text = $this->extract_node_text_for_typography($settings);
      $style = $this->match_text_style_for_node($text_styles, $current_text, $used_styles, $style_cursor);
      $is_heading = $this->is_heading_typography_node($node);
      $fallback_type = $this->find_styleguide_type_scale_item($style_guide, $node, $is_heading);

      $typography = isset($settings['_typography']) && is_array($settings['_typography']) ? $settings['_typography'] : [];

      $font_family = '';
      if (!empty($style['fontFamily'])) {
        $font_family = $this->normalise_bricks_font_family((string) $style['fontFamily']);
      }
      if ($font_family === '') {
        $font_family = $is_heading ? $heading_font : $body_font;
      }
      if ($font_family === '' && !empty($fallback_type['fontFamily'])) {
        $font_family = $this->normalise_bricks_font_family((string) $fallback_type['fontFamily']);
      }
      if ($font_family !== '') {
        $typography['font-family'] = $font_family;
      }

      $style_map = [
        'fontSize' => 'font-size',
        'fontWeight' => 'font-weight',
        'lineHeight' => 'line-height',
        'textAlign' => 'text-align',
      ];

      foreach ($style_map as $export_key => $bricks_key) {
        if (!empty($style[$export_key])) {
          $typography[$bricks_key] = sanitize_text_field((string) $style[$export_key]);
        }
      }

      foreach ($style_map as $export_key => $bricks_key) {
        if (!isset($typography[$bricks_key]) && !empty($fallback_type[$export_key])) {
          $typography[$bricks_key] = sanitize_text_field((string) $fallback_type[$export_key]);
        }
      }

      if (!empty($typography)) {
        $settings['_typography'] = $typography;
        $nodes[$index]['settings'] = $settings;
      }
    }

    return $nodes;
  }

  private function is_typography_node(array $node): bool {
    $name = (string) ($node['name'] ?? '');
    if (in_array($name, ['heading', 'text', 'text-basic', 'rich-text', 'button', 'post-title'], true)) {
      return true;
    }

    $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
    $tag = strtolower((string) ($settings['tag'] ?? ''));
    return in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'], true);
  }

  private function is_heading_typography_node(array $node): bool {
    $name = (string) ($node['name'] ?? '');
    if (in_array($name, ['heading', 'post-title'], true)) {
      return true;
    }

    $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
    $tag = strtolower((string) ($settings['tag'] ?? ''));
    if (preg_match('/^h[1-6]$/', $tag)) {
      return true;
    }

    $label = strtolower((string) ($node['label'] ?? ''));
    return strpos($label, 'heading') !== false || strpos($label, 'title') !== false;
  }

  private function extract_node_text_for_typography(array $settings): string {
    foreach (['text', 'title', 'heading', 'content', 'description', 'label', 'buttonText', 'link_text'] as $key) {
      if (!isset($settings[$key])) continue;
      $value = $settings[$key];
      if (is_array($value)) {
        $value = wp_json_encode($value);
      }
      $text = $this->normalise_text_for_typography_match((string) $value);
      if ($text !== '') {
        return $text;
      }
    }

    return '';
  }

  private function match_text_style_for_node(array $text_styles, string $text, array &$used_styles, int &$style_cursor): array {
    if (empty($text_styles)) {
      return [];
    }

    if ($text !== '') {
      foreach ($text_styles as $index => $style) {
        if (!empty($used_styles[$index]) || !is_array($style)) continue;
        $style_text = $this->normalise_text_for_typography_match((string) ($style['text'] ?? ''));
        if ($style_text !== '' && $style_text === $text) {
          $used_styles[$index] = true;
          return $style;
        }
      }
    }

    while (isset($text_styles[$style_cursor]) && !empty($used_styles[$style_cursor])) {
      $style_cursor++;
    }
    if (!isset($text_styles[$style_cursor]) || !is_array($text_styles[$style_cursor])) {
      return [];
    }

    $used_styles[$style_cursor] = true;
    return $text_styles[$style_cursor++];
  }

  private function normalise_text_for_typography_match(string $text): string {
    $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string) $text);
  }

  private function normalise_bricks_font_family(string $font): string {
    $font = trim(str_replace(["\r", "\n", "\t"], ' ', $font));
    if ($font === '') {
      return '';
    }

    $font = preg_replace('/[;{}<>]/', '', $font);
    $font = preg_replace('/\s+/', ' ', $font);
    $font = preg_replace('/\s*,\s*/', ', ', (string) $font);
    return substr((string) $font, 0, 180);
  }

  private function find_styleguide_type_scale_item(array $style_guide, array $node, bool $is_heading): array {
    $typography = isset($style_guide['typography']) && is_array($style_guide['typography']) ? $style_guide['typography'] : [];
    if (empty($typography)) {
      return [];
    }

    $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
    $tag = strtolower((string) ($settings['tag'] ?? ''));
    $wanted = $is_heading ? ($tag !== '' ? $tag : 'h2') : 'body';

    foreach ($typography as $item) {
      if (!is_array($item)) continue;
      $cls = strtolower((string) ($item['cls'] ?? ''));
      $label = strtolower((string) ($item['label'] ?? ''));
      if ($cls === $wanted || $label === $wanted) {
        return $item;
      }
    }

    foreach ($typography as $item) {
      if (!is_array($item)) continue;
      $cls = strtolower((string) ($item['cls'] ?? ''));
      if ($is_heading && in_array($cls, ['h1', 'h2', 'h3'], true)) {
        return $item;
      }
      if (!$is_heading && in_array($cls, ['body', 'small'], true)) {
        return $item;
      }
    }

    return [];
  }

  /**
   * Bake the project's real images directly into the Bricks `image` element
   * settings so they render natively in the Bricks builder and on the
   * front end, instead of relying solely on the runtime JS overlay (which only
   * runs when code execution is enabled on the clone). Image URLs are assigned
   * in document order, matching the round-robin order used by the overlay.
   *
   * @param array<int, array<string, mixed>> $nodes
   * @param array<int, string>               $image_urls Data URIs / URLs for this section.
   * @return array<int, array<string, mixed>>
   */
  private function bake_section_images(array $nodes, array $image_urls): array {
    if (empty($image_urls)) {
      return $nodes;
    }

    $slot = 0;
    $total = count($image_urls);

    foreach ($nodes as $index => $node) {
      if (!is_array($node) || ($node['name'] ?? '') !== 'image') {
        continue;
      }
      if ($slot >= $total) {
        break;
      }

      $url = trim((string) $image_urls[$slot]);
      $slot++;
      if ($url === '') {
        continue;
      }

      $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
      $image = isset($settings['image']) && is_array($settings['image']) ? $settings['image'] : [];

      $image['url'] = $url;
      $image['external'] = true;
      // The baked URL replaces any media-library reference, so drop attachment
      // metadata that would otherwise point at a non-existent media ID.
      unset($image['id'], $image['full'], $image['filename'], $image['size']);

      $settings['image'] = $image;
      $nodes[$index]['settings'] = $settings;
    }

    return $nodes;
  }

  /**
   * Bake the project's logo directly into every Bricks `logo` element so the
   * published clone shows the real brand logo instead of the site name. The
   * logo is written as an external URL (data URI) and constrained to a sane
   * height so it does not render oversized on the clone (the `logoHeight`
   * control emits `height` CSS on `.bricks-site-logo`, which also caps external
   * logos that Bricks would otherwise render at their natural size).
   *
   * @param array<int, array<string, mixed>> $nodes
   * @param string                           $logo_url Data URI / URL for the logo.
   * @return array<int, array<string, mixed>>
   */
  private function bake_logo_assets(array $nodes, string $logo_url): array {
    $logo_url = trim($logo_url);
    if ($logo_url === '') {
      return $nodes;
    }

    foreach ($nodes as $index => $node) {
      if (!is_array($node) || ($node['name'] ?? '') !== 'logo') {
        continue;
      }

      $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
      $logo = isset($settings['logo']) && is_array($settings['logo']) ? $settings['logo'] : [];

      $logo['url'] = $logo_url;
      $logo['external'] = true;
      // Drop any attachment reference so Bricks renders the external URL rather
      // than looking up a media ID that does not exist on the clone.
      unset($logo['id'], $logo['full'], $logo['filename'], $logo['size']);

      $settings['logo'] = $logo;

      // Constrain the logo size unless the template already sets an explicit
      // height/width, otherwise an external logo renders at its natural (often
      // oversized) dimensions.
      $has_height = isset($settings['logoHeight']) && (is_numeric($settings['logoHeight']) || strpos((string) $settings['logoHeight'], 'px') !== false);
      $has_width = isset($settings['logoWidth']) && (is_numeric($settings['logoWidth']) || strpos((string) $settings['logoWidth'], 'px') !== false);
      if (!$has_height && !$has_width) {
        $settings['logoHeight'] = '48px';
      }

      $nodes[$index]['settings'] = $settings;
    }

    return $nodes;
  }

  /**
   * Bake the section background colour directly into the Bricks root element's
   * `_background` setting so the published clone shows the correct section
   * background natively, instead of relying on the runtime JS overlay (which
   * only runs when code execution is enabled on the clone). The colour is
   * resolved from the section's own `bg_color`, falling back to the
   * alternating style-guide backgrounds via `bg_index`.
   *
   * @param array<int, array<string, mixed>> $nodes
   * @param string                           $section_bg Resolved CSS colour.
   * @return array<int, array<string, mixed>>
   */
  private function bake_section_background(array $nodes, string $section_bg): array {
    $section_bg = trim($section_bg);
    if ($section_bg === '') {
      return $nodes;
    }

    foreach ($nodes as $index => $node) {
      if (!is_array($node)) continue;
      if ((int) ($node['parent'] ?? 0) !== 0) continue;
      if (($node['name'] ?? '') === 'code') continue;

      $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
      $background = isset($settings['_background']) && is_array($settings['_background']) ? $settings['_background'] : [];

      $background['color'] = [
        'hex' => $section_bg,
        'raw' => $section_bg,
      ];

      $settings['_background'] = $background;
      $nodes[$index]['settings'] = $settings;
      break;
    }

    return $nodes;
  }

  /**
   * Normalise the export's global classes into the shape Bricks expects for the
   * `globalClasses` POST param (a list of `['id'=>, 'name'=>, 'settings'=>]`).
   * Bricks references styling by class name, so a missing name is filled from
   * the class id to keep the reference intact.
   *
   * @param array<int, mixed> $global_classes
   * @return array<int, array<string, mixed>>
   */
  private function normalise_global_classes_for_target(array $global_classes): array {
    $normalised = [];
    foreach ($global_classes as $class) {
      if (!is_array($class)) {
        continue;
      }
      $id = (string) ($class['id'] ?? '');
      if ($id === '') {
        continue;
      }
      $name = trim((string) ($class['name'] ?? ''));
      if ($name === '') {
        $name = $id;
      }
      $class['id'] = $id;
      $class['name'] = $name;
      if (!isset($class['settings']) || !is_array($class['settings'])) {
        $class['settings'] = [];
      }
      $normalised[] = $class;
    }

    return array_values($normalised);
  }

  /**
   * Normalise the export's theme styles into the keyed shape Bricks stores in
   * the BRICKS_DB_THEME_STYLES option (`[id => ['label'=>, 'settings'=>]]`).
   *
   * Crucially, each style is given an "Entire website" condition
   * (`settings.conditions.conditions = [['main'=>'any']]`) when it lacks one.
   * Without conditions Bricks' set_active_style() skips the style entirely, so
   * the colours, background colours, typography and border radii would never be
   * applied on the clone — which is exactly the symptom we are fixing.
   *
   * @param array<int, mixed> $theme_styles
   * @return array<string, array<string, mixed>>
   */
  private function normalise_theme_styles_for_target(array $theme_styles): array {
    $normalised = [];
    $position = 0;

    foreach ($theme_styles as $style) {
      if (!is_array($style)) {
        continue;
      }

      $settings = isset($style['settings']) && is_array($style['settings']) ? $style['settings'] : [];
      $label = trim((string) ($style['title'] ?? $style['label'] ?? ''));
      if ($label === '') {
        $label = 'AISB Theme Style ' . ($position + 1);
      }

      // Deterministic id so re-publishing updates the same style instead of
      // piling up duplicates.
      $source_id = (string) ($style['id'] ?? '');
      $id = 'aisb-theme-style-' . substr(md5($source_id . '|' . $label . '|' . wp_json_encode($settings)), 0, 12);

      // Ensure a site-wide condition so the style is actually activated.
      $conditions = isset($settings['conditions']['conditions']) && is_array($settings['conditions']['conditions'])
        ? $settings['conditions']['conditions']
        : [];
      if (empty($conditions)) {
        $conditions = [[
          'id' => substr(md5($id . '-condition'), 0, 6),
          'main' => 'any',
        ]];
      }
      $settings['conditions'] = ['conditions' => array_values($conditions)];

      $normalised[$id] = [
        'label' => $label,
        'settings' => $settings,
      ];
      $position++;
    }

    return $normalised;
  }

  private function tag_runtime_section_root(array $nodes, int $section_index): array {
    foreach ($nodes as $index => $node) {
      if (!is_array($node)) continue;
      if ((int) ($node['parent'] ?? 0) !== 0) continue;
      if (($node['name'] ?? '') === 'code') continue;

      $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
      $attributes = isset($settings['_attributes']) && is_array($settings['_attributes']) ? $settings['_attributes'] : [];
      $attributes = array_values(array_filter($attributes, static function ($attribute): bool {
        return !is_array($attribute) || (string) ($attribute['name'] ?? '') !== self::RUNTIME_SECTION_ATTRIBUTE;
      }));
      $attributes[] = [
        'id' => $this->new_bricks_id(),
        'name' => self::RUNTIME_SECTION_ATTRIBUTE,
        'value' => (string) $section_index,
      ];

      $settings['_attributes'] = $attributes;
      $nodes[$index]['settings'] = $settings;
      break;
    }

    return $nodes;
  }

  private function build_runtime_code_element(array $runtime_payload): array {
    $payload_json = wp_json_encode($runtime_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $payload_json = is_string($payload_json) ? str_replace('</', '<\/', $payload_json) : '{}';
    $javascript = $this->build_runtime_script($payload_json);
    $user_id = get_current_user_id();

    return [
      'id' => $this->new_bricks_id(),
      'name' => 'code',
      'parent' => 0,
      'children' => [],
      'settings' => [
        'executeCode' => true,
        'signature' => md5($javascript),
        'user_id' => $user_id > 0 ? $user_id : 1,
        'time' => time(),
        'javascriptCode' => $javascript,
      ],
      'label' => 'AISB Publish Runtime',
    ];
  }

  private function build_runtime_script(string $payload_json): string {
    $script = <<<'JS'
(function () {
  var payload = __AISB_PAYLOAD__;
  if (!payload) return;

  function getGuide() {
    return payload.style_guide && typeof payload.style_guide === "object"
      ? payload.style_guide
      : {};
  }

  function findGuideColour(name) {
    var colours = Array.isArray(getGuide().colours) ? getGuide().colours : [];
    var match = colours.find(function (colour) {
      return colour && colour.name === name;
    });
    return match && match.hex ? String(match.hex) : "";
  }

  function getLuminance(hex) {
    hex = String(hex || "").trim();
    if (!/^#[0-9a-f]{6}$/i.test(hex)) return 1;
    var r = parseInt(hex.slice(1, 3), 16) / 255;
    var g = parseInt(hex.slice(3, 5), 16) / 255;
    var b = parseInt(hex.slice(5, 7), 16) / 255;
    function convert(channel) {
      return channel <= 0.03928
        ? channel / 12.92
        : Math.pow((channel + 0.055) / 1.055, 2.4);
    }
    r = convert(r);
    g = convert(g);
    b = convert(b);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  }

  function ensureHeadNode(tagName, id) {
    var node = document.getElementById(id);
    if (node) return node;
    node = document.createElement(tagName);
    node.id = id;
    document.head.appendChild(node);
    return node;
  }

  function buildFontsUrl(guide) {
    var families = [guide.headingFont, guide.bodyFont].filter(function (font) {
      return !!String(font || "").trim();
    });
    if (!families.length) return "";
    return (
      "https://fonts.googleapis.com/css2?" +
      families
        .map(function (font) {
          return (
            "family=" +
            encodeURIComponent(String(font).trim()).replace(/%20/g, "+") +
            ":wght@400;600;700"
          );
        })
        .join("&") +
      "&display=swap"
    );
  }

  function buildStyleCss(guide) {
    var colours = Array.isArray(guide.colours) ? guide.colours : [];
    var find = function (name) {
      var match = colours.find(function (colour) {
        return colour && colour.name === name;
      });
      return match && match.hex ? String(match.hex) : "";
    };

    var primary = colours[0] && colours[0].hex ? String(colours[0].hex) : "";
    var accent = find("Accent") || (colours[2] && colours[2].hex ? String(colours[2].hex) : "");
    var dark = find("Dark") || (colours[3] && colours[3].hex ? String(colours[3].hex) : "");
    var light = find("Light") || (colours[4] && colours[4].hex ? String(colours[4].hex) : "");
    var neutral = find("Neutral") || (colours[5] && colours[5].hex ? String(colours[5].hex) : "");

    var css = "";
    css += ":root{";
    if (primary) css += "--bricks-color-primary:" + primary + ";--e-global-color-primary:" + primary + ";";
    if (accent) css += "--bricks-color-accent:" + accent + ";--e-global-color-accent:" + accent + ";";
    if (dark) css += "--bricks-color-dark:" + dark + ";";
    if (light) css += "--bricks-color-light:" + light + ";";
    if (neutral) css += "--bricks-color-neutral:" + neutral + ";";
    css += "}";

    if (guide.headingFont) {
      css +=
        "h1,h2,h3,h4,h5,h6,.brxe-heading{font-family:" +
        guide.headingFont +
        ",sans-serif !important;}";
    }
    if (guide.bodyFont) {
      css +=
        "body,p,.brxe-text,.brxe-text-basic,.brxe-rich-text,.brxe-post-content,li,td,th,label,figcaption,blockquote{font-family:" +
        guide.bodyFont +
        ",sans-serif !important;}";
    }
    if (primary) {
      var buttonText = getLuminance(primary) < 0.4 ? "#ffffff" : "#1a1a1a";
      css +=
        ".brxe-button,.bricks-button{background-color:" +
        primary +
        " !important;border-color:" +
        primary +
        " !important;color:" +
        buttonText +
        " !important;}";
      css += "a:not(.brxe-button){color:" + primary + " !important;}";
    }
    if (accent) {
      css +=
        ".brxe-icon-svg svg,.brxe-icon svg{color:" + accent + " !important;fill:" + accent + " !important;}";
    }
    css += "img[data-aisb-logo='1']{max-height:60px !important;width:auto !important;object-fit:contain !important;}";
    return css;
  }

  function applyStyleGuide() {
    var guide = getGuide();
    var style = ensureHeadNode("style", "aisb-publish-runtime-style");
    style.textContent = buildStyleCss(guide);

    var url = buildFontsUrl(guide);
    if (url) {
      var link = ensureHeadNode("link", "aisb-publish-runtime-fonts");
      link.rel = "stylesheet";
      link.href = url;
    }
  }

  function isLogoImage(img) {
    if (!img) return false;
    if (img.getAttribute("data-aisb-logo") === "1") return true;
    if (img.classList && img.classList.contains("bricks-site-logo")) return true;
    return !!(img.closest && img.closest(".brxe-logo"));
  }

  function applyLogo() {
    var guide = getGuide();
    var logoUrl = String(guide.logoUrl || "").trim();
    if (!logoUrl) return;

    var logos = Array.from(
      document.querySelectorAll("img.bricks-site-logo, .brxe-logo img, img[data-aisb-logo='1']"),
    );
    logos.forEach(function (img) {
      img.src = logoUrl;
      img.srcset = "";
      img.setAttribute("data-aisb-logo", "1");
    });
  }

  function buildSectionRootMap() {
    var map = {};
    Array.from(document.querySelectorAll("[data-aisb-runtime-section]"))
      .forEach(function (el) {
        var slot = String(el.getAttribute("data-aisb-runtime-section") || "");
        if (slot && !map[slot]) map[slot] = el;
      });
    return map;
  }

  function normaliseSelector(selector) {
    var raw = String(selector || "").trim();
    if (!raw) return "";
    var parts = raw.split(/\s*>\s*/).filter(Boolean);
    if (parts.length <= 1) return "";
    return parts.slice(1).join(" > ");
  }

  function findPatchTarget(root, selector) {
    if (!root) return null;
    var relative = normaliseSelector(selector);
    if (!relative) return root;
    try {
      return root.querySelector(relative);
    } catch (err) {
      return null;
    }
  }

  function extractSafeInlineColor(el) {
    if (!el || !el.getAttribute) return "";
    var styleAttr = String(el.getAttribute("style") || "");
    var match = styleAttr.match(/(?:^|;)\s*color\s*:\s*([^;]+)/i);
    if (!match) return "";
    var value = String(match[1] || "").trim();
    return /^(#[0-9a-f]{3,8}|rgba?\(\s*[\d\s.,%]+\)|hsla?\(\s*[\d\s.,%]+\)|var\(\s*--[a-z0-9_-]+\s*\)|[a-z-]+)$/i.test(value)
      ? value
      : "";
  }

  function appendSanitizedRichText(parent, sourceNode, doc) {
    if (!sourceNode || !parent || !doc) return;
    if (sourceNode.nodeType === 3) {
      parent.appendChild(doc.createTextNode(sourceNode.textContent || ""));
      return;
    }
    if (sourceNode.nodeType !== 1) return;

    var tag = String(sourceNode.tagName || "").toLowerCase();
    if (tag === "br") {
      parent.appendChild(doc.createElement("br"));
      return;
    }
    if (tag === "p") {
      if (parent.childNodes && parent.childNodes.length) {
        parent.appendChild(doc.createElement("br"));
        parent.appendChild(doc.createElement("br"));
      }
      Array.from(sourceNode.childNodes || []).forEach(function (child) {
        appendSanitizedRichText(parent, child, doc);
      });
      return;
    }
    if (tag === "span") {
      var span = doc.createElement("span");
      var color = extractSafeInlineColor(sourceNode);
      if (color) span.style.color = color;
      Array.from(sourceNode.childNodes || []).forEach(function (child) {
        appendSanitizedRichText(span, child, doc);
      });
      parent.appendChild(span);
      return;
    }
    Array.from(sourceNode.childNodes || []).forEach(function (child) {
      appendSanitizedRichText(parent, child, doc);
    });
  }

  function hasRichTextMarkup(text) {
    return /<\s*br\s*\/?>|<\s*\/?\s*p\b|<\s*span\b/i.test(String(text || ""));
  }

  function applyTextPatch(el, text) {
    if (!el) return;
    var raw = String(text || "");
    if (!hasRichTextMarkup(raw)) {
      el.innerText = raw;
      return;
    }

    var template = document.createElement("template");
    template.innerHTML = raw;
    var fragment = document.createDocumentFragment();
    Array.from(template.content.childNodes || []).forEach(function (child) {
      appendSanitizedRichText(fragment, child, document);
    });

    while (el.firstChild) el.removeChild(el.firstChild);
    if (fragment.childNodes && fragment.childNodes.length) {
      el.appendChild(fragment);
    } else {
      el.innerText = template.content.textContent || raw;
    }
  }

  function applyMirror(root) {
    if (!root) return;
    var win = document.defaultView;
    var mirrored = false;
    Array.from(root.children || []).forEach(function (child) {
      if (!child || !win) return;
      var dir = win.getComputedStyle(child).flexDirection;
      if (dir === "row") {
        child.style.setProperty("flex-direction", "row-reverse", "important");
        mirrored = true;
      }
    });
    if (mirrored) return;

    Array.from(root.children || []).forEach(function (child) {
      if (!child || !child.classList) return;
      if (
        child.classList.contains("brxe-container") ||
        child.classList.contains("brxe-block") ||
        child.classList.contains("brxe-div")
      ) {
        child.style.setProperty("flex-direction", "row-reverse", "important");
      }
    });
  }

  function applySectionImages(root, urls) {
    if (!root || !Array.isArray(urls) || !urls.length) return;
    var imgs = Array.from(root.querySelectorAll("img")).filter(function (img) {
      return !isLogoImage(img);
    });
    imgs.forEach(function (img, index) {
      var src = urls[index] || urls[index % urls.length];
      if (!src) return;
      img.src = src;
      img.srcset = "";
      img.style.objectFit = "cover";
    });
  }

  function applySectionBackground(root, color) {
    if (!root || !color) return;
    root.style.setProperty("background-color", color, "important");

    var isDark = getLuminance(color) < 0.4;
    var paletteDark = findGuideColour("Dark") || "#1a1a1a";
    var paletteLight = findGuideColour("Light") || "#ffffff";
    var primary = findGuideColour("Primary") || findGuideColour("Accent");
    var headingColor = isDark ? "#ffffff" : paletteDark;
    var bodyColor = isDark ? "rgba(255,255,255,0.85)" : paletteDark;
    var buttonText = isDark
      ? "#ffffff"
      : getLuminance(primary || "#000000") < 0.4
        ? "#ffffff"
        : "#1a1a1a";

    root
      .querySelectorAll("h1,h2,h3,h4,h5,h6,.brxe-heading")
      .forEach(function (el) {
        el.style.setProperty("color", headingColor, "important");
      });
    root
      .querySelectorAll("p,.brxe-text,.brxe-text-basic,.brxe-rich-text,.brxe-post-content,li,td,th,label,figcaption,blockquote")
      .forEach(function (el) {
        el.style.setProperty("color", bodyColor, "important");
      });
    root.querySelectorAll(".brxe-button,.bricks-button").forEach(function (el) {
      el.style.setProperty("color", buttonText, "important");
    });
    root.querySelectorAll("a:not(.brxe-button)").forEach(function (el) {
      el.style.setProperty("color", isDark ? paletteLight : (primary || paletteDark), "important");
    });
  }

  function applySectionPatches(root, section) {
    var patch = Array.isArray(section.patch) ? section.patch.slice() : [];
    if (!patch.length) return;

    var order = { mirror: 0, text: 1, img: 2, css: 3 };
    patch.sort(function (left, right) {
      return (order[left.type] || 9) - (order[right.type] || 9);
    });

    patch.forEach(function (op) {
      if (!op || !op.type) return;
      if (op.type === "mirror") {
        if (op.mirrored) applyMirror(root);
        return;
      }

      var target = findPatchTarget(root, op.selector || "");
      if (!target) return;

      if (op.type === "text") {
        applyTextPatch(target, op.text || "");
        return;
      }

      if (op.type === "img") {
        if (op.src) {
          target.src = op.src;
          target.srcset = "";
          target.style.objectFit = "cover";
        }
        return;
      }

      if (op.type === "css" && op.prop) {
        if (op.cascade === "section" && (op.prop === "background" || op.prop === "background-color" || op.prop === "background-image")) {
          root.style.setProperty(op.prop, op.value || "", "important");
          return;
        }
        target.style.setProperty(op.prop, op.value || "", "important");
      }
    });
  }

  function pageHref(page) {
    var slug = String((page && page.slug) || "").replace(/^\/+|\/+$/g, "");
    var base = String(payload.site_url || "").replace(/\/+$/g, "");
    if (!slug || (page && page.is_front)) {
      return base ? base + "/" : "/";
    }
    return base ? base + "/" + slug + "/" : "/" + slug + "/";
  }

  function applyNavMenus() {
    var pages = Array.isArray(payload.pages) ? payload.pages : [];
    if (!pages.length) return;

    document.querySelectorAll(".bricks-nav-menu").forEach(function (ul) {
      if (ul.dataset.aisbNavInjected === "1") return;

      var topItems = Array.from(ul.children).filter(function (child) {
        return child.tagName === "LI";
      });
      if (!topItems.length) return;

      var template = topItems[0].cloneNode(true);
      var templateSub = template.querySelector(".sub-menu");
      if (templateSub) templateSub.remove();
      var templateToggle = template.querySelector(".brx-submenu-toggle");
      if (templateToggle) templateToggle.remove();
      template.classList.remove("menu-item-has-children");

      topItems.forEach(function (item) {
        item.remove();
      });

      pages.forEach(function (page) {
        var item = template.cloneNode(true);
        var link = item.querySelector("a");
        if (link) {
          var label = String((page && page.title) || (page && page.slug) || "").trim();
          link.href = pageHref(page);
          var textSpan = link.querySelector(
            "span:not(.brx-submenu-toggle):not([class*='icon']):not([class*='svg'])",
          );
          if (textSpan) {
            textSpan.textContent = label;
          } else {
            link.textContent = label;
          }
        }
        ul.appendChild(item);
      });

      ul.dataset.aisbNavInjected = "1";
    });
  }

  function applySections() {
    var map = buildSectionRootMap();
    var sections = Array.isArray(payload.sections) ? payload.sections : [];
    sections.forEach(function (section) {
      var root = map[String(section.slot || "")];
      if (!root) return;
      applySectionBackground(root, section.bg_color || "");
      applySectionImages(root, Array.isArray(section.image_urls) ? section.image_urls : []);
      applySectionPatches(root, section);
    });
  }

  function run() {
    applyStyleGuide();
    applyLogo();
    applyNavMenus();
    applySections();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", run, { once: true });
  } else {
    run();
  }
  window.setTimeout(run, 300);
})();
JS;

    return str_replace('__AISB_PAYLOAD__', $payload_json, $script);
  }

  private function convert_local_url_to_data_uri(string $url): string {
    if ($url === '' || strpos($url, 'data:') === 0) return $url;

    $home = untrailingslashit(home_url());
    if ($home === '' || strpos($url, $home) !== 0) {
      return $url;
    }

    $relative = substr($url, strlen($home));
    $path = wp_normalize_path(untrailingslashit(ABSPATH) . $relative);
    if (!is_file($path) || !is_readable($path)) {
      return $url;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    $content = @file_get_contents($path);
    if ($content === false) {
      return $url;
    }

    $mime = function_exists('mime_content_type') ? mime_content_type($path) : '';
    if (!is_string($mime) || $mime === '') {
      $mime = 'application/octet-stream';
    }

    return 'data:' . $mime . ';base64,' . base64_encode($content);
  }

  private function ensure_target_page($connection, string $prefix, string $slug, string $title, int $menu_order, string $site_url) {
    $existing = $this->find_page_by_slug($connection, $prefix, $slug);
    if ($existing) {
      $existing['meta_keys'] = $this->fetch_target_meta_keys($connection, $prefix, (int) $existing['ID']);
      return $existing;
    }

    $inserted = $this->insert_page($connection, $prefix, $slug, $title, $menu_order, $site_url);
    if (is_wp_error($inserted)) {
      return $inserted;
    }

    return [
      'ID' => (int) $inserted,
      'post_name' => $slug,
      'post_title' => $title,
      'meta_keys' => $this->fetch_target_meta_keys($connection, $prefix, (int) $inserted),
    ];
  }

  private function find_page_by_slug($connection, string $prefix, string $slug): ?array {
    $posts_table = $prefix . 'posts';
    $stmt = $connection->prepare(
      "SELECT ID, post_name, post_title FROM {$posts_table} WHERE post_type='page' AND post_name=? ORDER BY ID ASC LIMIT 1"
    );
    if (!$stmt) {
      return null;
    }

    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($row) ? $row : null;
  }

  private function update_page_identity($connection, string $prefix, int $post_id, string $title, string $slug, int $menu_order, string $site_url) {
    $posts_table = $prefix . 'posts';
    $now = gmdate('Y-m-d H:i:s');
    $guid = $site_url !== ''
      ? trailingslashit($site_url) . '?page_id=' . $post_id
      : '';

    $stmt = $connection->prepare(
      "UPDATE {$posts_table}
       SET post_title=?, post_name=?, post_status='publish', post_type='page', post_parent=0,
           menu_order=?, post_modified=?, post_modified_gmt=?, guid=IF(guid='', ?, guid)
       WHERE ID=?"
    );
    if (!$stmt) {
      return new WP_Error('aisb_instawp_page_update_prepare_failed', $connection->error, ['status' => 502]);
    }

    $stmt->bind_param('ssisssi', $title, $slug, $menu_order, $now, $now, $guid, $post_id);
    $stmt->execute();
    $error = $stmt->error;
    $stmt->close();

    if ($error !== '') {
      return new WP_Error('aisb_instawp_page_update_failed', $error, ['status' => 502]);
    }

    return true;
  }

  private function insert_page($connection, string $prefix, string $slug, string $title, int $menu_order, string $site_url) {
    $posts_table = $prefix . 'posts';
    $author_id = $this->lookup_author_id($connection, $prefix);
    $now = gmdate('Y-m-d H:i:s');

    $stmt = $connection->prepare(
      "INSERT INTO {$posts_table}
        (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status,
         comment_status, ping_status, post_name, post_modified, post_modified_gmt, post_parent,
         menu_order, post_type, post_mime_type, comment_count, guid)
       VALUES (?, ?, ?, '', ?, '', 'publish', 'closed', 'closed', ?, ?, ?, 0, ?, 'page', '', 0, '')"
    );
    if (!$stmt) {
      return new WP_Error('aisb_instawp_page_insert_prepare_failed', $connection->error, ['status' => 502]);
    }

    $stmt->bind_param('issssssi', $author_id, $now, $now, $title, $slug, $now, $now, $menu_order);
    $stmt->execute();
    $error = $stmt->error;
    $insert_id = (int) $connection->insert_id;
    $stmt->close();

    if ($error !== '') {
      return new WP_Error('aisb_instawp_page_insert_failed', $error, ['status' => 502]);
    }

    $identity = $this->update_page_identity($connection, $prefix, $insert_id, $title, $slug, $menu_order, $site_url);
    if (is_wp_error($identity)) {
      return $identity;
    }

    return $insert_id;
  }

  private function lookup_author_id($connection, string $prefix): int {
    $users_table = $prefix . 'users';
    if (!$this->table_exists($connection, $users_table)) {
      return 1;
    }

    $result = $connection->query("SELECT ID FROM {$users_table} ORDER BY ID ASC LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    return $row ? max(1, (int) ($row['ID'] ?? 1)) : 1;
  }

  private function set_front_page($connection, string $prefix, int $page_id) {
    $show_on_front = $this->upsert_option_value($connection, $prefix, 'show_on_front', 'page');
    if (is_wp_error($show_on_front)) {
      return $show_on_front;
    }

    return $this->upsert_option_value($connection, $prefix, 'page_on_front', (string) $page_id);
  }

  private function re_id_bricks_nodes(array $nodes): array {
    $map = [];
    foreach ($nodes as $node) {
      if (!is_array($node)) continue;
      $old_id = (string) ($node['id'] ?? '');
      if ($old_id === '') continue;
      $map[$old_id] = $this->new_bricks_id();
    }

    $output = [];
    foreach ($nodes as $node) {
      if (!is_array($node)) continue;

      $old_id = (string) ($node['id'] ?? '');
      if ($old_id === '' || !isset($map[$old_id])) continue;

      $node['id'] = $map[$old_id];

      if (isset($node['parent']) && $node['parent'] !== 0 && $node['parent'] !== '0') {
        $old_parent = (string) $node['parent'];
        if (isset($map[$old_parent])) {
          $node['parent'] = $map[$old_parent];
        }
      }

      if (isset($node['children']) && is_array($node['children'])) {
        $node['children'] = array_values(array_filter(array_map(function ($child_id) use ($map) {
          $child_id = (string) $child_id;
          return isset($map[$child_id]) ? $map[$child_id] : null;
        }, $node['children']), static function ($value): bool {
          return $value !== null;
        }));
      }

      $output[] = $node;
    }

    return $output;
  }

  private function new_bricks_id(): string {
    $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $id = '';
    for ($index = 0; $index < 6; $index++) {
      $id .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $id;
  }

  private function force_root_parent_zero(array $nodes): array {
    foreach ($nodes as $index => $node) {
      if (!is_array($node)) continue;
      if (($node['name'] ?? '') === 'section') {
        $nodes[$index]['parent'] = 0;
        break;
      }
    }
    return $nodes;
  }

  private function apply_section_padding(array $nodes): array {
    $top = '3rem';
    $bottom = '3rem';

    foreach ($nodes as $index => $node) {
      if (!is_array($node)) continue;

      $is_root_wrapper = in_array(($node['name'] ?? ''), ['section', 'container', 'block'], true)
        && (int) ($node['parent'] ?? 0) === 0;
      if (!$is_root_wrapper) continue;

      $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
      $padding = isset($settings['_padding']) && is_array($settings['_padding']) ? $settings['_padding'] : [];
      $padding['top'] = $top;
      $padding['bottom'] = $bottom;
      if (!isset($padding['left'])) $padding['left'] = '';
      if (!isset($padding['right'])) $padding['right'] = '';

      $settings['_padding'] = $padding;
      foreach (array_keys($settings) as $key) {
        if (is_string($key) && strpos($key, '_padding:') === 0) {
          unset($settings[$key]);
        }
      }

      $nodes[$index]['settings'] = $settings;
    }

    return $nodes;
  }

  private function bake_border_radius_patches(array $nodes, array $section): array {
    $radius_by_id = [];

    $patches = isset($section['patch']) && is_array($section['patch']) ? $section['patch'] : [];
    foreach ($patches as $op) {
      if (!is_array($op)) continue;
      if (($op['type'] ?? '') !== 'css' || ($op['prop'] ?? '') !== 'border-radius') continue;
      $selector = (string) ($op['selector'] ?? '');
      $id = $this->extract_bricks_id_from_selector($selector);
      if ($id === '') continue;
      $radius = $this->normalise_bricks_radius_value($op['value'] ?? '');
      if ($radius === '') continue;
      $radius_by_id[$id] = $radius;
    }

    $content = isset($section['content']) && is_array($section['content']) ? $section['content'] : [];
    $style_sources = [];
    foreach (['element_styles', 'border_radii'] as $key) {
      if (isset($content[$key]) && is_array($content[$key])) {
        $style_sources = array_merge($style_sources, array_filter($content[$key], 'is_array'));
      }
    }

    foreach ($style_sources as $style) {
      $radius = $this->normalise_bricks_radius_value($style['borderRadius'] ?? ($style['_borderRadius'] ?? ($style['value'] ?? '')));
      if ($radius === '') continue;

      $id = '';
      if (!empty($style['id'])) {
        $id = preg_replace('/^brxe-/', '', (string) $style['id']);
      }
      if ($id === '' && !empty($style['selector'])) {
        $id = $this->extract_bricks_id_from_selector((string) $style['selector']);
      }
      if ($id === '') continue;

      $radius_by_id[$id] = $radius;
    }

    if (empty($radius_by_id)) {
      return $nodes;
    }

    foreach ($nodes as $index => $node) {
      if (!is_array($node)) continue;
      $id = (string) ($node['id'] ?? '');
      if ($id === '' || !isset($radius_by_id[$id])) continue;

      $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
      $settings = $this->apply_bricks_radius_settings($settings, $radius_by_id[$id]);
      $nodes[$index]['settings'] = $settings;
    }

    return $nodes;
  }

  private function extract_bricks_id_from_selector(string $selector): string {
    if (preg_match_all('/brxe-([A-Za-z0-9_-]+)/', $selector, $matches) && !empty($matches[1])) {
      return (string) end($matches[1]);
    }
    return '';
  }

  private function normalise_bricks_radius_value($value): string {
    if (is_int($value) || is_float($value)) {
      $number = is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
      return $number . 'px';
    }

    if (is_string($value)) {
      $value = trim($value);
      if ($value === '') return '';
      return is_numeric($value) ? $value . 'px' : sanitize_text_field($value);
    }

    if (!is_array($value)) return '';

    foreach (['raw', 'value', 'css'] as $key) {
      if (array_key_exists($key, $value)) {
        $normalised = $this->normalise_bricks_radius_value($value[$key]);
        if ($normalised !== '') return $normalised;
      }
    }

    $values = [];
    foreach (['top', 'right', 'bottom', 'left'] as $key) {
      $values[] = array_key_exists($key, $value) ? $this->normalise_bricks_radius_value($value[$key]) : '';
    }
    if (implode('', $values) === '') {
      $values = [];
      foreach (['topLeft', 'topRight', 'bottomRight', 'bottomLeft'] as $key) {
        $values[] = array_key_exists($key, $value) ? $this->normalise_bricks_radius_value($value[$key]) : '';
      }
    }
    if (implode('', $values) === '') return '';

    $fallback = '';
    foreach ($values as $radius) {
      if ($radius !== '') {
        $fallback = $radius;
        break;
      }
    }
    if ($fallback === '') return '';

    $values = array_map(static function ($radius) use ($fallback) {
      return $radius !== '' ? $radius : $fallback;
    }, $values);

    if ($values[0] === $values[1] && $values[0] === $values[2] && $values[0] === $values[3]) {
      return $values[0];
    }
    if ($values[0] === $values[2] && $values[1] === $values[3]) {
      return $values[0] . ' ' . $values[1];
    }
    if ($values[1] === $values[3]) {
      return $values[0] . ' ' . $values[1] . ' ' . $values[2];
    }

    return implode(' ', $values);
  }

  private function apply_bricks_radius_settings(array $settings, string $radius): array {
    $radius = $this->normalise_bricks_radius_value($radius);
    if ($radius === '') {
      return $settings;
    }

    $settings['_borderRadius'] = $radius;
    $settings['borderRadius'] = $radius;
    if (!isset($settings['_border']) || !is_array($settings['_border'])) {
      $settings['_border'] = [];
    }
    $settings['_border']['radius'] = $this->expand_bricks_radius_corners($radius);
    return $settings;
  }

  private function expand_bricks_radius_corners(string $radius): array {
    $parts = preg_split('/\s+/', trim($radius));
    $parts = array_values(array_filter((array) $parts, static function ($part) {
      return $part !== '';
    }));
    if (empty($parts)) {
      $parts = [$radius];
    }

    if (count($parts) === 1) {
      $tl = $tr = $br = $bl = $parts[0];
    } elseif (count($parts) === 2) {
      $tl = $br = $parts[0];
      $tr = $bl = $parts[1];
    } elseif (count($parts) === 3) {
      $tl = $parts[0];
      $tr = $bl = $parts[1];
      $br = $parts[2];
    } else {
      $tl = $parts[0];
      $tr = $parts[1];
      $br = $parts[2];
      $bl = $parts[3];
    }

    return [
      'top' => $tl,
      'right' => $tr,
      'bottom' => $br,
      'left' => $bl,
    ];
  }

  private function neutralize_accordion_query_loops(array $nodes): array {
    $accordion_ids = [];
    foreach ($nodes as $node) {
      if (!is_array($node)) continue;
      if (($node['name'] ?? '') !== 'accordion-nested') continue;

      $id = (string) ($node['id'] ?? '');
      if ($id !== '') {
        $accordion_ids[$id] = true;
      }
    }

    if (!$accordion_ids) {
      return $nodes;
    }

    foreach ($nodes as $index => $node) {
      if (!is_array($node)) continue;

      $parent = (string) ($node['parent'] ?? '');
      if (!isset($accordion_ids[$parent])) continue;
      if (!isset($node['settings']) || !is_array($node['settings'])) continue;
      if (!isset($node['settings']['query'])) continue;

      unset($nodes[$index]['settings']['query']);
      if (isset($nodes[$index]['settings']['hasLoop'])) unset($nodes[$index]['settings']['hasLoop']);
      if (isset($nodes[$index]['settings']['_query'])) unset($nodes[$index]['settings']['_query']);
    }

    return array_values($nodes);
  }

  private function publish_page(array $args) {
    $source = $this->load_source_payload(
      (int) $args['project_id'],
      (int) $args['sitemap_version_id'],
      (string) $args['page_slug']
    );
    if (is_wp_error($source)) {
      return $source;
    }

    $settings = AISB_Settings::get_settings();
    $api_key = trim((string) ($settings['instawp_api_key'] ?? ''));
    $template_id = trim((string) ($settings['instawp_template_id'] ?? ''));
    $timeout = $this->normalise_instawp_timeout($settings['instawp_timeout'] ?? 240);

    if ($api_key === '' || $template_id === '') {
      return new WP_Error(
        'aisb_instawp_missing_settings',
        'Configure the InstaWP API key and template slug (or legacy template ID) in the AI Sitemap Builder settings.',
        ['status' => 400]
      );
    }

    $publish_site = $this->get_or_create_publish_site((int) $args['project_id'], (string) $args['site_name'], $template_id, $api_key, $timeout);
    if (is_wp_error($publish_site)) {
      return $publish_site;
    }

    $clone = is_array($publish_site['clone'] ?? null) ? $publish_site['clone'] : [];
    $site = is_array($publish_site['site'] ?? null) ? $publish_site['site'] : [];

    $project = get_post((int) $args['project_id']);
    $legacy_page = $this->build_legacy_export_page(
      $source['model'],
      (string) $args['page_slug'],
      (string) ($args['target_page_slug'] ?? '')
    );

    $publish_result = $this->publish_pages_via_xmlrpc(
      $site,
      $clone,
      $project instanceof WP_Post ? $project : null,
      [$legacy_page],
      [],
      [],
      (string) $legacy_page['slug'],
      $timeout
    );
    if (is_wp_error($publish_result)) {
      return $publish_result;
    }

    $this->clear_publish_session((int) $args['project_id']);

    $target_post_id = 0;
    $published_pages = isset($publish_result['published_pages']) && is_array($publish_result['published_pages'])
      ? $publish_result['published_pages']
      : [];
    if (!empty($published_pages[0]['post_id'])) {
      $target_post_id = (int) $published_pages[0]['post_id'];
    }

    return array_merge([
      'wp_url' => $site['site_url'],
      'wp_admin_url' => $site['admin_url'],
      'frontend_auto_login_url' => $this->create_frontend_auto_login_url($site),
      'magic_login_url' => (string) ($publish_site['magic_login_url'] ?? $this->extract_instawp_magic_login_url($clone)),
      'site_id' => (string) ($publish_site['site_id'] ?? $this->extract_first_value($clone, ['site_id', 'id'])),
      'reused_existing_site' => !empty($publish_site['reused_existing_site']),
      'target_post_id' => $target_post_id,
      'target_page_slug' => (string) $legacy_page['slug'],
    ], $publish_result);
  }

  private function load_source_payload(int $project_id, int $sitemap_version_id, string $page_slug) {
    global $wpdb;
    $table = $wpdb->prefix . 'aisb_wireframes';
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT model_json, compiled_bricks_json FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug=%s LIMIT 1",
      $project_id,
      $sitemap_version_id,
      $page_slug
    ), ARRAY_A);

    if (!$row) {
      return new WP_Error('aisb_instawp_missing_source', 'No wireframe row was found for that page.', ['status' => 404]);
    }

    $model = json_decode((string) ($row['model_json'] ?? ''), true);
    if (!is_array($model) || empty($model['sections']) || !is_array($model['sections'])) {
      return new WP_Error('aisb_instawp_missing_model', 'The source wireframe model is empty.', ['status' => 400]);
    }

    $compiled = json_decode((string) ($row['compiled_bricks_json'] ?? ''), true);
    if (!is_array($compiled) || empty($compiled['content']) || !is_array($compiled['content'])) {
      $compiled = $this->compiler->compile_page($model);
      if (empty($compiled['content']) || !is_array($compiled['content'])) {
        return new WP_Error('aisb_instawp_compile_failed', 'The source page could not be compiled to Bricks JSON.', ['status' => 500]);
      }

      $wpdb->update(
        $table,
        [
          'compiled_bricks_json' => wp_json_encode($compiled, JSON_UNESCAPED_SLASHES),
          'updated_at' => current_time('mysql'),
        ],
        [
          'project_id' => $project_id,
          'sitemap_version_id' => $sitemap_version_id,
          'page_slug' => $page_slug,
        ],
        ['%s', '%s'],
        ['%d', '%d', '%s']
      );
    }

    return [
      'model' => $model,
      'compiled' => $compiled,
    ];
  }

  private function prepare_payload_for_target(array $model, array $compiled, array $target_meta_keys) {
    $has_separate_chrome = in_array('_bricks_page_header_2', $target_meta_keys, true)
      || in_array('_bricks_page_footer_2', $target_meta_keys, true);

    if (!$has_separate_chrome) {
      return $compiled;
    }

    $filtered = $model;
    $filtered['sections'] = array_values(array_filter(
      isset($model['sections']) && is_array($model['sections']) ? $model['sections'] : [],
      static function ($section): bool {
        if (!is_array($section)) return false;
        $type = strtolower((string) ($section['type'] ?? ''));
        return !in_array($type, ['header', 'footer'], true);
      }
    ));

    if (empty($filtered['sections'])) {
      return new WP_Error(
        'aisb_instawp_no_content_sections',
        'The compiled page only contains header/footer sections, so there is no page body to inject.',
        ['status' => 400]
      );
    }

    $payload = $this->compiler->compile_page($filtered);
    if (empty($payload['content']) || !is_array($payload['content'])) {
      return new WP_Error('aisb_instawp_filtered_compile_failed', 'Failed to compile the page content without header/footer.', ['status' => 500]);
    }

    return $payload;
  }

  private function create_instant_site(string $template_id, string $api_key, string $site_name, int $timeout) {
    $template_slug = $this->resolve_template_slug($template_id);
    if (is_wp_error($template_slug)) {
      return $template_slug;
    }

    $url = trailingslashit(self::API_BASE) . 'sites/template';

    error_log('[AISB] create_instant_site: POST ' . $url . ' slug=' . $template_slug . ' site_name=' . $site_name);

    $request_timeout = max(120, min(300, $timeout));
    $response = wp_remote_post($url, [
      'timeout' => $request_timeout,
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      'body' => wp_json_encode([
        'slug' => $template_slug,
        'site_name' => $site_name,
      ]),
    ]);

    if (is_wp_error($response)) {
      error_log('[AISB] create_instant_site WP_Error: ' . $response->get_error_message());
      if ($this->should_use_resolve_fallback($response->get_error_message())) {
        return new WP_Error(
          'aisb_instawp_clone_timeout',
          'InstaWP did not finish the clone request within ' . $request_timeout . ' seconds. The service may still be provisioning the site; wait a moment and try Publish again.',
          ['status' => 504]
        );
      }

      return new WP_Error('aisb_instawp_request_failed', 'InstaWP clone request failed: ' . $response->get_error_message(), ['status' => 502]);
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);

    error_log('[AISB] create_instant_site response HTTP ' . $status . ': ' . substr($body, 0, 2000));

    if ($status < 200 || $status >= 300) {
      $message = 'InstaWP rejected the clone request.';
      if (is_array($decoded)) {
        $message = (string) ($decoded['message'] ?? $decoded['error'] ?? $message);
      } elseif (is_string($body) && $body !== '') {
        $message = wp_strip_all_tags($body);
      }
      return new WP_Error('aisb_instawp_bad_response', $message, ['status' => $status ?: 502]);
    }

    if (!is_array($decoded)) {
      return new WP_Error('aisb_instawp_invalid_json', 'InstaWP returned an invalid JSON response.', ['status' => 502]);
    }

    if (isset($decoded['status']) && $decoded['status'] === false) {
      $message = (string) ($decoded['message'] ?? $decoded['error'] ?? 'InstaWP rejected the site creation request.');
      return new WP_Error('aisb_instawp_bad_response', $message, ['status' => 502]);
    }

    $has_credentials = $this->extract_first_value($decoded, ['wp_username']) !== ''
      && $this->extract_first_value($decoded, ['wp_password']) !== '';
    if (!$has_credentials) {
      $site_id = $this->extract_first_value($decoded, ['site_id', 'id']);
      if ($site_id === '') {
        return new WP_Error(
          'aisb_instawp_site_not_ready',
          'InstaWP accepted the site creation request but did not return a site ID or WordPress credentials yet.',
          ['status' => 502]
        );
      }

      $details = $this->await_instawp_site_credentials($site_id, $api_key, $timeout);
      if (is_wp_error($details)) {
        return $details;
      }

      return [
        'create' => $decoded,
        'details' => $details,
      ];
    }

    return $decoded;
  }

  private function resolve_template_slug(string $template_reference) {
    $template_reference = trim($template_reference);
    if ($template_reference === '') {
      return new WP_Error('aisb_instawp_missing_template', 'No InstaWP template slug or ID was configured.', ['status' => 400]);
    }

    if (!ctype_digit($template_reference)) {
      $slug = sanitize_title($template_reference);
      if ($slug !== '') {
        return $slug;
      }
    }

    if (isset(self::LEGACY_TEMPLATE_SLUGS[$template_reference])) {
      return self::LEGACY_TEMPLATE_SLUGS[$template_reference];
    }

    return new WP_Error(
      'aisb_instawp_unknown_template',
      'The configured InstaWP template is a legacy numeric ID with no known slug mapping. Store the template slug instead, for example bricks-ai-base.',
      ['status' => 400]
    );
  }

  private function await_instawp_site_credentials(string $site_id, string $api_key, int $timeout) {
    $max_wait_seconds = max(30, min(90, $timeout + 20));
    $poll_interval_microseconds = 2000000;
    $deadline = time() + $max_wait_seconds;
    $last_details = null;
    $attempt = 0;

    while (time() <= $deadline) {
      $attempt++;
      $details = $this->fetch_instawp_site_details($site_id, $api_key, $timeout);
      if (!is_wp_error($details)) {
        $last_details = $details;

        $wp_url = $this->extract_first_value($details, ['wp_url', 'site_url', 'url']);
        $wp_username = $this->extract_first_value($details, ['wp_username']);
        $wp_password = $this->extract_first_value($details, ['wp_password']);
        if ($wp_url !== '' && $wp_username !== '' && $wp_password !== '') {
          return $details;
        }
      } else {
        error_log('[AISB] InstaWP site details poll failed on attempt ' . $attempt . ': ' . $details->get_error_message());
      }

      usleep($poll_interval_microseconds);
    }

    return new WP_Error(
      'aisb_instawp_site_not_ready',
      'InstaWP is still provisioning the new site and has not returned WordPress credentials yet.',
      ['status' => 502, 'site_id' => $site_id, 'details' => $last_details]
    );
  }

  private function fetch_instawp_site_details(string $site_id, string $api_key, int $timeout) {
    $url = trailingslashit(self::API_BASE) . 'sites/' . rawurlencode($site_id);
    $response = wp_remote_get($url, [
      'timeout' => max(10, min(120, $timeout)),
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Accept' => 'application/json',
      ],
    ]);

    if (is_wp_error($response)) {
      return new WP_Error('aisb_instawp_site_details_failed', $response->get_error_message(), ['status' => 502]);
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);

    if ($status < 200 || $status >= 300) {
      $message = 'InstaWP rejected the site details request.';
      if (is_array($decoded)) {
        $message = (string) ($decoded['message'] ?? $decoded['error'] ?? $message);
      }

      return new WP_Error('aisb_instawp_site_details_failed', $message, ['status' => $status ?: 502]);
    }

    if (!is_array($decoded)) {
      return new WP_Error('aisb_instawp_site_details_invalid_json', 'InstaWP returned invalid site details JSON.', ['status' => 502]);
    }

    return $decoded;
  }

  private function extract_database_config(array $payload) {
    error_log('[AISB] extract_database_config: scanning payload keys=' . implode(',', array_keys($payload)));

    foreach ($this->flatten_arrays($payload) as $candidate) {
      if (!is_array($candidate)) continue;

      $database = null;
      if (isset($candidate['database']) && is_array($candidate['database'])) {
        $database = $candidate['database'];
      } elseif (isset($candidate['db']) && is_array($candidate['db'])) {
        $database = $candidate['db'];
      } else {
        $database = $candidate;
      }

      $host = $this->pick_first_non_empty($database, ['db_host', 'host', 'hostname']);
      $name = $this->pick_first_non_empty($database, ['db_name', 'database_name', 'database', 'name']);
      $user = $this->pick_first_non_empty($database, ['db_user', 'db_username', 'username', 'user']);
      $password = $this->pick_first_non_empty($database, ['db_password', 'db_pass', 'password']);

      if ($host === '' || $name === '' || $user === '' || $password === '') {
        continue;
      }

      error_log('[AISB] extract_database_config: found credentials host=' . $host . ' db=' . $name);
      return [
        'host' => $host,
        'name' => $name,
        'user' => $user,
        'password' => $password,
        'prefix' => $this->pick_first_non_empty($database, ['db_prefix', 'table_prefix', 'prefix']),
      ];
    }

    error_log('[AISB] extract_database_config: FAILED — full payload: ' . wp_json_encode($payload));
    return new WP_Error(
      'aisb_instawp_missing_db_credentials',
      'The InstaWP response did not include database credentials in a recognised format.',
      ['status' => 502]
    );
  }

  private function connect_database(array $config) {
    if (!class_exists('mysqli')) {
      return new WP_Error('aisb_instawp_missing_mysqli', 'The mysqli extension is not available in this PHP runtime.', ['status' => 500]);
    }

    [$host, $port] = $this->split_host_and_port((string) $config['host']);
    error_log('[AISB] connect_database: connecting to ' . $host . ':' . ($port ?: 3306) . ' db=' . $config['name']);

    $connection = @new mysqli($host, (string) $config['user'], (string) $config['password'], (string) $config['name'], $port ?: 3306);
    if ($connection->connect_errno) {
      error_log('[AISB] connect_database FAILED: #' . $connection->connect_errno . ' ' . $connection->connect_error);
      return new WP_Error('aisb_instawp_db_connect_failed', $connection->connect_error, ['status' => 502]);
    }

    error_log('[AISB] connect_database: connected OK');
    $connection->set_charset('utf8mb4');
    return $connection;
  }

  private function detect_table_prefix($connection, string $hint) {
    $hint = preg_replace('/[^A-Za-z0-9_]/', '', $hint);
    if ($hint !== '' && $this->table_exists($connection, $hint . 'posts') && $this->table_exists($connection, $hint . 'options')) {
      return $hint;
    }

    $result = $connection->query("SHOW TABLES");
    if (!$result) {
      return new WP_Error('aisb_instawp_prefix_detect_failed', $connection->error, ['status' => 502]);
    }

    $prefixes = [];
    while ($row = $result->fetch_row()) {
      $table = (string) ($row[0] ?? '');
      if (substr($table, -7) === '_posts') {
        $prefixes[] = substr($table, 0, -5);
      } elseif (substr($table, -5) === 'posts') {
        $prefixes[] = substr($table, 0, -5);
      }
    }

    $prefixes = array_values(array_filter(array_unique($prefixes), static function ($prefix): bool {
      return $prefix !== '';
    }));

    foreach ($prefixes as $prefix) {
      if ($this->table_exists($connection, $prefix . 'options') && $this->table_exists($connection, $prefix . 'postmeta')) {
        return $prefix;
      }
    }

    if ($this->table_exists($connection, 'wp_posts') && $this->table_exists($connection, 'wp_options')) {
      return 'wp_';
    }

    return new WP_Error('aisb_instawp_prefix_missing', 'Could not detect the WordPress table prefix on the cloned site.', ['status' => 502]);
  }

  private function resolve_target_post($connection, string $prefix, int $target_post_id, string $target_page_slug) {
    $posts_table = $prefix . 'posts';
    $meta_keys = [];

    if ($target_post_id > 0) {
      $stmt = $connection->prepare("SELECT ID, post_name, post_title FROM {$posts_table} WHERE ID=? LIMIT 1");
      if (!$stmt) {
        return new WP_Error('aisb_instawp_target_prepare_failed', $connection->error, ['status' => 502]);
      }
      $stmt->bind_param('i', $target_post_id);
      $stmt->execute();
      $result = $stmt->get_result();
      $post = $result ? $result->fetch_assoc() : null;
      $stmt->close();
      if (!$post) {
        return new WP_Error('aisb_instawp_target_post_missing', 'The requested target_post_id does not exist on the cloned site.', ['status' => 404]);
      }
      $post['meta_keys'] = $this->fetch_target_meta_keys($connection, $prefix, (int) $post['ID']);
      return $post;
    }

    $target_page_slug = sanitize_title($target_page_slug);

    if ($target_page_slug !== '') {
      $stmt = $connection->prepare("SELECT ID, post_name, post_title FROM {$posts_table} WHERE post_type='page' AND post_status IN ('publish', 'draft', 'private') AND post_name=? ORDER BY FIELD(post_status, 'publish', 'draft', 'private'), ID ASC LIMIT 1");
      if (!$stmt) {
        return new WP_Error('aisb_instawp_target_prepare_failed', $connection->error, ['status' => 502]);
      }
      $stmt->bind_param('s', $target_page_slug);
      $stmt->execute();
      $result = $stmt->get_result();
      $post = $result ? $result->fetch_assoc() : null;
      $stmt->close();
      if ($post) {
        $post['meta_keys'] = $this->fetch_target_meta_keys($connection, $prefix, (int) $post['ID']);
        return $post;
      }
    }

    $front_page_id = $this->lookup_front_page_id($connection, $prefix);
    if ($front_page_id > 0) {
      $stmt = $connection->prepare("SELECT ID, post_name, post_title FROM {$posts_table} WHERE ID=? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param('i', $front_page_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $post = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ($post) {
          $post['meta_keys'] = $this->fetch_target_meta_keys($connection, $prefix, (int) $post['ID']);
          return $post;
        }
      }
    }

    $result = $connection->query("SELECT ID, post_name, post_title FROM {$posts_table} WHERE post_type='page' AND post_status IN ('publish', 'draft', 'private') ORDER BY FIELD(post_status, 'publish', 'draft', 'private'), ID ASC LIMIT 1");
    $post = $result ? $result->fetch_assoc() : null;
    if ($post) {
      $post['meta_keys'] = $this->fetch_target_meta_keys($connection, $prefix, (int) $post['ID']);
      return $post;
    }

    return new WP_Error('aisb_instawp_target_missing', 'No target page could be found on the cloned site.', ['status' => 404]);
  }

  private function fetch_target_meta_keys($connection, string $prefix, int $post_id): array {
    $postmeta_table = $prefix . 'postmeta';
    $stmt = $connection->prepare(
      "SELECT meta_key FROM {$postmeta_table} WHERE post_id=? AND meta_key IN ('_bricks_page_content_2', '_bricks_data', '_bricks_page_header_2', '_bricks_page_footer_2')"
    );
    if (!$stmt) return [];
    $stmt->bind_param('i', $post_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $keys = [];
    while ($result && ($row = $result->fetch_assoc())) {
      $keys[] = (string) $row['meta_key'];
    }
    $stmt->close();
    return array_values(array_unique($keys));
  }

  private function inject_page_payload($connection, string $prefix, int $post_id, array $payload, array $existing_meta_keys) {
    if (empty($payload['content']) || !is_array($payload['content'])) {
      return new WP_Error('aisb_instawp_empty_payload', 'The Bricks payload is empty.', ['status' => 400]);
    }

    $content = maybe_serialize($payload['content']);
    $keys_to_write = [];

    if (in_array('_bricks_page_content_2', $existing_meta_keys, true)) {
      $keys_to_write[] = '_bricks_page_content_2';
    }
    if (in_array('_bricks_data', $existing_meta_keys, true)) {
      $keys_to_write[] = '_bricks_data';
    }
    if (empty($keys_to_write)) {
      $keys_to_write[] = '_bricks_page_content_2';
      $keys_to_write[] = '_bricks_data';
    }

    foreach ($keys_to_write as $meta_key) {
      $updated = $this->upsert_meta_value($connection, $prefix, $post_id, $meta_key, $content);
      if (is_wp_error($updated)) {
        return $updated;
      }
    }

    if (!empty($payload['header']) && is_array($payload['header'])) {
      $header = maybe_serialize($payload['header']);
      $updated = $this->upsert_meta_value($connection, $prefix, $post_id, '_bricks_page_header_2', $header);
      if (is_wp_error($updated)) {
        return $updated;
      }
      $keys_to_write[] = '_bricks_page_header_2';
    }

    if (!empty($payload['footer']) && is_array($payload['footer'])) {
      $footer = maybe_serialize($payload['footer']);
      $updated = $this->upsert_meta_value($connection, $prefix, $post_id, '_bricks_page_footer_2', $footer);
      if (is_wp_error($updated)) {
        return $updated;
      }
      $keys_to_write[] = '_bricks_page_footer_2';
    }

    return array_values(array_unique($keys_to_write));
  }

  private function merge_global_classes($connection, string $prefix, array $incoming) {
    if (empty($incoming)) {
      return 0;
    }

    $options_table = $prefix . 'options';
    $stmt = $connection->prepare("SELECT option_value FROM {$options_table} WHERE option_name='bricks_global_classes' LIMIT 1");
    if (!$stmt) {
      return new WP_Error('aisb_instawp_option_prepare_failed', $connection->error, ['status' => 502]);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $existing = [];
    if (!empty($row['option_value'])) {
      $decoded = maybe_unserialize($row['option_value']);
      if (is_array($decoded)) $existing = $decoded;
    }

    $merged = $existing;
    $before = count($merged);
    $seen = [];
    foreach ($existing as $class) {
      if (!is_array($class)) continue;
      $key = (string) ($class['id'] ?? $class['name'] ?? '');
      if ($key !== '') $seen[$key] = true;
    }

    foreach ($incoming as $class) {
      if (!is_array($class)) continue;
      if (!isset($class['name']) && isset($class['id'])) {
        $class['name'] = $class['id'];
      }
      $key = (string) ($class['id'] ?? $class['name'] ?? '');
      if ($key === '' || isset($seen[$key])) continue;
      $merged[] = $class;
      $seen[$key] = true;
    }

    if (count($merged) === $before) {
      return 0;
    }

    $serialized = maybe_serialize($merged);
    $updated = $this->upsert_option_value($connection, $prefix, 'bricks_global_classes', $serialized);
    if (is_wp_error($updated)) {
      return $updated;
    }

    return count($merged) - $before;
  }

  private function upsert_meta_value($connection, string $prefix, int $post_id, string $meta_key, string $meta_value) {
    $postmeta_table = $prefix . 'postmeta';
    $stmt = $connection->prepare("SELECT meta_id FROM {$postmeta_table} WHERE post_id=? AND meta_key=? LIMIT 1");
    if (!$stmt) {
      return new WP_Error('aisb_instawp_meta_prepare_failed', $connection->error, ['status' => 502]);
    }
    $stmt->bind_param('is', $post_id, $meta_key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row && !empty($row['meta_id'])) {
      $meta_id = (int) $row['meta_id'];
      $stmt = $connection->prepare("UPDATE {$postmeta_table} SET meta_value=? WHERE meta_id=?");
      if (!$stmt) {
        return new WP_Error('aisb_instawp_meta_update_prepare_failed', $connection->error, ['status' => 502]);
      }
      $stmt->bind_param('si', $meta_value, $meta_id);
      $stmt->execute();
      $error = $stmt->error;
      $stmt->close();
      if ($error !== '') {
        return new WP_Error('aisb_instawp_meta_update_failed', $error, ['status' => 502]);
      }
      return true;
    }

    $stmt = $connection->prepare("INSERT INTO {$postmeta_table} (post_id, meta_key, meta_value) VALUES (?, ?, ?)");
    if (!$stmt) {
      return new WP_Error('aisb_instawp_meta_insert_prepare_failed', $connection->error, ['status' => 502]);
    }
    $stmt->bind_param('iss', $post_id, $meta_key, $meta_value);
    $stmt->execute();
    $error = $stmt->error;
    $stmt->close();
    if ($error !== '') {
      return new WP_Error('aisb_instawp_meta_insert_failed', $error, ['status' => 502]);
    }
    return true;
  }

  private function upsert_option_value($connection, string $prefix, string $option_name, string $option_value) {
    $options_table = $prefix . 'options';
    $stmt = $connection->prepare("SELECT option_id FROM {$options_table} WHERE option_name=? LIMIT 1");
    if (!$stmt) {
      return new WP_Error('aisb_instawp_option_lookup_failed', $connection->error, ['status' => 502]);
    }
    $stmt->bind_param('s', $option_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row && !empty($row['option_id'])) {
      $option_id = (int) $row['option_id'];
      $stmt = $connection->prepare("UPDATE {$options_table} SET option_value=? WHERE option_id=?");
      if (!$stmt) {
        return new WP_Error('aisb_instawp_option_update_prepare_failed', $connection->error, ['status' => 502]);
      }
      $stmt->bind_param('si', $option_value, $option_id);
      $stmt->execute();
      $error = $stmt->error;
      $stmt->close();
      if ($error !== '') {
        return new WP_Error('aisb_instawp_option_update_failed', $error, ['status' => 502]);
      }
      return true;
    }

    $autoload = 'yes';
    $stmt = $connection->prepare("INSERT INTO {$options_table} (option_name, option_value, autoload) VALUES (?, ?, ?)");
    if (!$stmt) {
      return new WP_Error('aisb_instawp_option_insert_prepare_failed', $connection->error, ['status' => 502]);
    }
    $stmt->bind_param('sss', $option_name, $option_value, $autoload);
    $stmt->execute();
    $error = $stmt->error;
    $stmt->close();
    if ($error !== '') {
      return new WP_Error('aisb_instawp_option_insert_failed', $error, ['status' => 502]);
    }
    return true;
  }

  private function lookup_front_page_id($connection, string $prefix): int {
    $options_table = $prefix . 'options';
    $stmt = $connection->prepare("SELECT option_value FROM {$options_table} WHERE option_name='page_on_front' LIMIT 1");
    if (!$stmt) return 0;
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ? (int) ($row['option_value'] ?? 0) : 0;
  }

  private function table_exists($connection, string $table): bool {
    $stmt = $connection->prepare('SHOW TABLES LIKE ?');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();
    return $exists;
  }

  private function split_host_and_port(string $host): array {
    $host = trim($host);
    if ($host === '') return ['localhost', 3306];

    if (strpos($host, '://') !== false) {
      $parts = wp_parse_url($host);
      if (!empty($parts['host'])) {
        return [(string) $parts['host'], isset($parts['port']) ? (int) $parts['port'] : 3306];
      }
    }

    if (preg_match('/^(.+):(\d+)$/', $host, $matches)) {
      return [$matches[1], (int) $matches[2]];
    }

    return [$host, 3306];
  }

  private function flatten_arrays(array $data): array {
    $stack = [$data];
    $flattened = [];
    while ($stack) {
      $item = array_pop($stack);
      if (!is_array($item)) continue;
      $flattened[] = $item;
      foreach ($item as $value) {
        if (is_array($value)) {
          $stack[] = $value;
        }
      }
    }
    return $flattened;
  }

  private function pick_first_non_empty(array $data, array $keys): string {
    foreach ($keys as $key) {
      if (!array_key_exists($key, $data)) continue;
      $value = $data[$key];
      if (is_scalar($value) && trim((string) $value) !== '') {
        return trim((string) $value);
      }
    }
    return '';
  }

  private function extract_first_value(array $data, array $keys): string {
    foreach ($this->flatten_arrays($data) as $candidate) {
      $value = $this->pick_first_non_empty($candidate, $keys);
      if ($value !== '') return $value;
    }
    return '';
  }
}