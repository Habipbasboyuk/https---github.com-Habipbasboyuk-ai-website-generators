<?php
/**
 * Plugin Name: Agency Portal
 * Plugin URI:
 * Description: Phase 1 CPT + meta schema for agency client/projects/tasks/change requests. Configurable enumerations + task dependencies.
 * Version: 0.1.0
 * Author: Archer Websites
 * Author URI: https://archerwebsites.be
 * License: GPL2+
 */


if (!defined('ABSPATH')) exit;

final class Agency_Portal_Plugin {
  const OPT_KEY = 'ap_settings';

  const NONCE_ACTION = 'ap_save_meta';
  const NONCE_NAME   = 'ap_meta_nonce';

  const ROLE_CLIENT = 'ap_client';

  public static function init() {
    add_action('init', [__CLASS__, 'register_cpts']);
    add_action('init', [__CLASS__, 'register_roles_caps']);
    add_action('admin_menu', [__CLASS__, 'register_admin_pages']);
    add_action('admin_init', [__CLASS__, 'register_settings']);

    add_action('add_meta_boxes', [__CLASS__, 'register_meta_boxes']);
    add_action('save_post', [__CLASS__, 'save_post_meta'], 10, 2);

    // Phase 1.1: task list filters
    add_action('restrict_manage_posts', [__CLASS__, 'tasks_admin_filters']);
    add_action('pre_get_posts', [__CLASS__, 'apply_tasks_admin_filters']);

    // Phase 2: Blocks
    add_action('init', [__CLASS__, 'register_blocks']);

    // Phase 2: Permission hardening for comments
    add_action('pre_comment_on_post', [__CLASS__, 'guard_task_comment_access']);

    register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);
  }

  // --------------------------------------------------
  // Activation: defaults + create portal page template
  // --------------------------------------------------
  public static function on_activate() {
    $opt = get_option(self::OPT_KEY);
    if (!is_array($opt)) $opt = [];

    $defaults = self::default_settings();
    foreach ($defaults as $k => $v) {
      if (!array_key_exists($k, $opt)) $opt[$k] = $v;
      if (is_array($v) && (empty($opt[$k]) || !is_array($opt[$k]))) $opt[$k] = $v;
    }
    update_option(self::OPT_KEY, $opt);

    self::register_cpts();
    self::register_roles_caps();
    flush_rewrite_rules();

    self::maybe_create_default_portal_page();
  }

  private static function maybe_create_default_portal_page() {
    $existing = get_page_by_path('client-portal');
    if ($existing) return;

    $content = <<<HTML
<!-- wp:heading -->
<h2>Client Portal</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Welcome. Below you can find your projects, tasks, and your profile details.</p>
<!-- /wp:paragraph -->

<!-- wp:agency-portal/client-profile {"title":"Your Profile","showContact":true,"showBilling":false} /-->

<!-- wp:agency-portal/projects {"title":"Your Projects","layout":"table","showStatus":true} /-->

<!-- wp:agency-portal/tasks {"title":"Your Tasks","layout":"table","showProject":true,"showDates":true,"showStatus":true,"showOnlyClientVisible":true} /-->

<!-- wp:agency-portal/task-comments {"title":"Task Comments","mode":"selected"} /-->
HTML;

    wp_insert_post([
      'post_title'   => 'Client Portal',
      'post_name'    => 'client-portal',
      'post_status'  => 'publish',
      'post_type'    => 'page',
      'post_content' => $content,
    ]);
  }

  // -----------------------------
  // Settings
  // -----------------------------
  private static function default_settings(): array {
    return [
      // Enumerations (editable)
      'project_statuses' => ['planned','active','on_hold','completed','cancelled'],
      'project_visibility' => ['internal','client_visible'],

      'task_statuses' => ['backlog','in_progress','review','blocked','done'],
      'task_types'    => ['design','development','content','seo','qa','support','change_request'],
      'task_priorities' => ['low','normal','high','urgent'],

      'change_request_statuses' => ['new','reviewed','approved','rejected','converted'],
      'change_request_priorities' => ['low','normal','high','urgent'],

      // Phase 1.1 hardening
      'default_task_client_visible' => 1,
      'enforce_same_project_dependencies' => 0,
    ];
  }

  private static function default_enums(): array {
    $s = self::default_settings();
    return [
      'project_statuses' => $s['project_statuses'],
      'project_visibility' => $s['project_visibility'],
      'task_statuses' => $s['task_statuses'],
      'task_types' => $s['task_types'],
      'task_priorities' => $s['task_priorities'],
      'change_request_statuses' => $s['change_request_statuses'],
      'change_request_priorities' => $s['change_request_priorities'],
    ];
  }

  public static function get_setting(string $key, $default = null) {
    $opt = get_option(self::OPT_KEY, []);
    return $opt[$key] ?? $default;
  }

  public static function get_enum(string $key): array {
    $opt = get_option(self::OPT_KEY, []);
    $defaults = self::default_enums();
    $list = $opt[$key] ?? $defaults[$key] ?? [];
    if (!is_array($list)) $list = [];
    $list = array_values(array_filter(array_map('sanitize_key', $list)));
    if (count($list) === 0 && isset($defaults[$key])) return $defaults[$key];
    return $list;
  }

  public static function sanitize_settings($input) {
    $defaults = self::default_settings();
    $out = is_array($input) ? $input : [];

    // Sanitize enums
    foreach (self::default_enums() as $key => $def) {
      $raw = $out[$key] ?? $def;
      if (is_string($raw)) $raw = preg_split('/\r\n|\r|\n/', $raw) ?: [];
      if (!is_array($raw)) $raw = [];
      $clean = array_values(array_filter(array_map('sanitize_key', $raw)));
      $out[$key] = count($clean) ? $clean : $def;
    }

    // Phase 1.1 settings
    $out['default_task_client_visible'] = isset($out['default_task_client_visible']) ? (int)$out['default_task_client_visible'] : (int)$defaults['default_task_client_visible'];
    $out['default_task_client_visible'] = $out['default_task_client_visible'] === 0 ? 0 : 1;

    $out['enforce_same_project_dependencies'] = isset($out['enforce_same_project_dependencies']) ? (int)$out['enforce_same_project_dependencies'] : (int)$defaults['enforce_same_project_dependencies'];
    $out['enforce_same_project_dependencies'] = $out['enforce_same_project_dependencies'] === 1 ? 1 : 0;

    return $out;
  }

  // -----------------------------
  // CPT Registration (Phase 1 + Phase 2: comments on tasks)
  // -----------------------------
  public static function register_cpts() {
    register_post_type('ap_client', [
      'labels' => ['name' => 'Clients', 'singular_name' => 'Client'],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'supports' => ['title','editor','revisions'],
      'menu_icon' => 'dashicons-businessperson',
      'show_in_rest' => true,
    ]);

    register_post_type('ap_project', [
      'labels' => ['name' => 'Projects', 'singular_name' => 'Project'],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'supports' => ['title','editor','revisions'],
      'menu_icon' => 'dashicons-portfolio',
      'show_in_rest' => true,
    ]);

    // IMPORTANT: support comments (Phase 2)
    register_post_type('ap_task', [
      'labels' => ['name' => 'Tasks', 'singular_name' => 'Task'],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'supports' => ['title','editor','revisions','comments'],
      'menu_icon' => 'dashicons-list-view',
      'show_in_rest' => true,
    ]);

    register_post_type('ap_change_request', [
      'labels' => ['name' => 'Change Requests', 'singular_name' => 'Change Request'],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'supports' => ['title','editor','revisions'],
      'menu_icon' => 'dashicons-feedback',
      'show_in_rest' => true,
    ]);
  }

  // -----------------------------
  // Roles + caps
  // -----------------------------
  public static function register_roles_caps() {
    // Create client role if not exists
    if (!get_role(self::ROLE_CLIENT)) {
      add_role(self::ROLE_CLIENT, 'Client', [
        'read' => true,
      ]);
    }
  }

  // -----------------------------
  // Admin pages (Settings)
  // -----------------------------
  public static function register_admin_pages() {
    add_menu_page(
      'Agency Portal',
      'Agency Portal',
      'manage_options',
      'ap_settings',
      [__CLASS__, 'render_settings_page'],
      'dashicons-admin-generic',
      58
    );
  }

  public static function register_settings() {
    register_setting('ap_settings_group', self::OPT_KEY, [
      'type' => 'array',
      'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
      'default' => self::default_settings()
    ]);

    add_settings_section('ap_enums_section', 'Enumerations', function () {
      echo '<p>Enter one value per line. Values are stored as slugs (lowercase, underscores).</p>';
    }, 'ap_settings');

    $enum_fields = [
      'project_statuses' => 'Project Statuses',
      'project_visibility' => 'Project Visibility Options',
      'task_statuses' => 'Task Statuses',
      'task_types' => 'Task Types',
      'task_priorities' => 'Task Priorities',
      'change_request_statuses' => 'Change Request Statuses',
      'change_request_priorities' => 'Change Request Priorities',
    ];

    foreach ($enum_fields as $key => $label) {
      add_settings_field(
        $key,
        $label,
        function() use ($key) { self::render_enum_textarea($key); },
        'ap_settings',
        'ap_enums_section'
      );
    }

    add_settings_section('ap_hardening_section', 'Phase 1.1 Hardening', function () {
      echo '<p>Operational defaults and rules.</p>';
    }, 'ap_settings');

    add_settings_field(
      'default_task_client_visible',
      'Default task visibility',
      [__CLASS__, 'render_default_task_visibility_field'],
      'ap_settings',
      'ap_hardening_section'
    );

    add_settings_field(
      'enforce_same_project_dependencies',
      'Enforce same-project dependencies',
      [__CLASS__, 'render_enforce_same_project_dependencies_field'],
      'ap_settings',
      'ap_hardening_section'
    );
  }

  private static function render_enum_textarea(string $key) {
    $vals = self::get_enum($key);
    $text = implode("\n", $vals);
    echo '<textarea class="large-text code" rows="6" name="'.esc_attr(self::OPT_KEY.'['.$key.']').'">'.esc_textarea($text).'</textarea>';
  }

  public static function render_default_task_visibility_field() {
    $val = (int) self::get_setting('default_task_client_visible', 1);
    echo '<label style="display:block; margin-bottom:6px;">';
    echo '<input type="radio" name="'.esc_attr(self::OPT_KEY.'[default_task_client_visible]').'" value="1" '.checked($val, 1, false).'> Default to <strong>Visible to client</strong>';
    echo '</label>';
    echo '<label style="display:block;">';
    echo '<input type="radio" name="'.esc_attr(self::OPT_KEY.'[default_task_client_visible]').'" value="0" '.checked($val, 0, false).'> Default to <strong>Internal</strong>';
    echo '</label>';
  }

  public static function render_enforce_same_project_dependencies_field() {
    $val = (int) self::get_setting('enforce_same_project_dependencies', 0);
    echo '<label>';
    echo '<input type="checkbox" name="'.esc_attr(self::OPT_KEY.'[enforce_same_project_dependencies]').'" value="1" '.checked($val, 1, false).'> Enforce that dependencies must belong to the same project';
    echo '</label>';
  }

  public static function render_settings_page() {
    if (!current_user_can('manage_options')) return;

    echo '<div class="wrap">';
    echo '<h1>Agency Portal Settings</h1>';
    echo '<p><strong>Manual onboarding:</strong> Create a WP user for the client, assign role <code>Client</code>, then link that user on the Client record.</p>';
    echo '<form method="post" action="options.php">';
    settings_fields('ap_settings_group');
    do_settings_sections('ap_settings');
    submit_button('Save settings');
    echo '</form>';
    echo '</div>';
  }

  // -----------------------------
  // Meta Boxes (manual onboarding = link WP user to client)
  // -----------------------------
  public static function register_meta_boxes() {
    add_meta_box('ap_client_meta', 'Client Details', [__CLASS__, 'render_client_meta'], 'ap_client', 'normal', 'high');
    add_meta_box('ap_project_meta', 'Project Settings', [__CLASS__, 'render_project_meta'], 'ap_project', 'normal', 'high');
    add_meta_box('ap_task_meta', 'Task Settings', [__CLASS__, 'render_task_meta'], 'ap_task', 'normal', 'high');
  }

  private static function nonce_field() {
    wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
  }

  public static function render_client_meta(\WP_Post $post) {
    self::nonce_field();

    $status = get_post_meta($post->ID, '_ap_client_status', true) ?: 'active';
    $code = get_post_meta($post->ID, '_ap_client_code', true);
    $email = get_post_meta($post->ID, '_ap_primary_contact_email', true);
    $name = get_post_meta($post->ID, '_ap_primary_contact_name', true);

    // Manual onboarding link
    $linked_user_id = (int) get_post_meta($post->ID, '_ap_client_wp_user_id', true);
    $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);

    echo '<p><label><strong>Client status</strong></label><br>';
    echo self::select_html('_ap_client_status', ['active','inactive','lead'], $status);
    echo '</p>';

    echo '<p><label><strong>Client code</strong> (optional)</label><br>';
    echo '<input class="widefat" name="_ap_client_code" value="'.esc_attr($code).'" /></p>';

    echo '<p><label><strong>Primary contact name</strong></label><br>';
    echo '<input class="widefat" name="_ap_primary_contact_name" value="'.esc_attr($name).'" /></p>';

    echo '<p><label><strong>Primary contact email</strong></label><br>';
    echo '<input class="widefat" type="email" name="_ap_primary_contact_email" value="'.esc_attr($email).'" /></p>';

    echo '<hr>';
    echo '<p><label><strong>Linked WP User (manual onboarding)</strong></label><br>';
    echo '<select name="_ap_client_wp_user_id" class="widefat">';
    echo '<option value="0">— Not linked —</option>';
    foreach ($users as $u) {
      echo '<option value="'.esc_attr($u->ID).'" '.selected($linked_user_id, $u->ID, false).'>'.esc_html($u->display_name).' ('.$u->user_email.')</option>';
    }
    echo '</select>';
    echo '<br><em>Create a WP user, assign role <code>Client</code>, then link here.</em>';
    echo '</p>';
  }

  public static function render_project_meta(\WP_Post $post) {
    self::nonce_field();

    $client_id = (int) get_post_meta($post->ID, '_ap_project_client_id', true);
    $status = get_post_meta($post->ID, '_ap_project_status', true) ?: (self::get_enum('project_statuses')[0] ?? 'planned');
    $visibility = get_post_meta($post->ID, '_ap_project_visibility', true) ?: 'client_visible';

    $clients = get_posts([
      'post_type' => 'ap_client',
      'numberposts' => -1,
      'orderby' => 'title',
      'order' => 'ASC',
      'post_status' => ['publish','draft'],
    ]);

    echo '<p><label><strong>Client</strong></label><br>';
    echo '<select name="_ap_project_client_id" class="widefat">';
    echo '<option value="0">— Select client —</option>';
    foreach ($clients as $c) {
      echo '<option value="'.esc_attr($c->ID).'" '.selected($client_id, $c->ID, false).'>'.esc_html($c->post_title).'</option>';
    }
    echo '</select></p>';

    echo '<p style="display:flex; gap:16px;">';
    echo '<span style="flex:1;"><label><strong>Status</strong></label><br>'.self::select_html('_ap_project_status', self::get_enum('project_statuses'), $status).'</span>';
    echo '<span style="flex:1;"><label><strong>Visibility</strong></label><br>'.self::select_html('_ap_project_visibility', self::get_enum('project_visibility'), $visibility).'</span>';
    echo '</p>';

    echo '<p><em>Even if a project is <code>client_visible</code>, individual tasks can still be internal.</em></p>';
  }

  public static function render_task_meta(\WP_Post $post) {
    self::nonce_field();

    $project_id = (int) get_post_meta($post->ID, '_ap_task_project_id', true);
    $status = get_post_meta($post->ID, '_ap_task_status', true) ?: (self::get_enum('task_statuses')[0] ?? 'backlog');

    $client_visible_meta = get_post_meta($post->ID, '_ap_task_client_visible', true);
    if ($client_visible_meta === '') $client_visible_meta = (string)(int) self::get_setting('default_task_client_visible', 1);
    $client_visible = (string)(int) $client_visible_meta;

    $start_dt = get_post_meta($post->ID, '_ap_task_start_datetime', true);
    $end_dt   = get_post_meta($post->ID, '_ap_task_end_datetime', true);

    $projects = get_posts([
      'post_type' => 'ap_project',
      'numberposts' => -1,
      'orderby' => 'title',
      'order' => 'ASC',
      'post_status' => ['publish','draft'],
    ]);

    echo '<p><label><strong>Project</strong></label><br>';
    echo '<select name="_ap_task_project_id" class="widefat">';
    echo '<option value="0">— Select project —</option>';
    foreach ($projects as $p) {
      echo '<option value="'.esc_attr($p->ID).'" '.selected($project_id, $p->ID, false).'>'.esc_html($p->post_title).'</option>';
    }
    echo '</select></p>';

    echo '<p><label><strong>Status</strong></label><br>';
    echo self::select_html('_ap_task_status', self::get_enum('task_statuses'), $status);
    echo '</p>';

    echo '<p><label><strong>Client visibility</strong></label><br>';
    echo '<label><input type="checkbox" name="_ap_task_client_visible" value="1" '.checked($client_visible, '1', false).'> Visible to client</label>';
    echo '</p>';

    echo '<p style="display:flex; gap:16px;">';
    echo '<span style="flex:1;"><label><strong>Start *</strong></label><br><input type="datetime-local" class="widefat" name="_ap_task_start_datetime" value="'.esc_attr(self::mysql_to_datetime_local($start_dt)).'"></span>';
    echo '<span style="flex:1;"><label><strong>End *</strong></label><br><input type="datetime-local" class="widefat" name="_ap_task_end_datetime" value="'.esc_attr(self::mysql_to_datetime_local($end_dt)).'"></span>';
    echo '</p>';

    echo '<p><em>Phase 2: clients can comment on tasks in the portal (read-only task details).</em></p>';
  }

  // -----------------------------
  // Save handlers (kept minimal here: focus on Phase 2)
  // -----------------------------
  public static function save_post_meta($post_id, $post) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)) return;
    if (!current_user_can('edit_post', $post_id)) return;

    switch ($post->post_type) {
      case 'ap_client':
        update_post_meta($post_id, '_ap_client_status', sanitize_key($_POST['_ap_client_status'] ?? 'active'));
        update_post_meta($post_id, '_ap_client_code', sanitize_text_field($_POST['_ap_client_code'] ?? ''));
        update_post_meta($post_id, '_ap_primary_contact_name', sanitize_text_field($_POST['_ap_primary_contact_name'] ?? ''));
        update_post_meta($post_id, '_ap_primary_contact_email', sanitize_email($_POST['_ap_primary_contact_email'] ?? ''));
        update_post_meta($post_id, '_ap_client_wp_user_id', (int)($_POST['_ap_client_wp_user_id'] ?? 0));
        break;

      case 'ap_project':
        update_post_meta($post_id, '_ap_project_client_id', (int)($_POST['_ap_project_client_id'] ?? 0));
        update_post_meta($post_id, '_ap_project_status', sanitize_key($_POST['_ap_project_status'] ?? 'planned'));
        update_post_meta($post_id, '_ap_project_visibility', sanitize_key($_POST['_ap_project_visibility'] ?? 'client_visible'));
        break;

      case 'ap_task':
        $project_id = (int)($_POST['_ap_task_project_id'] ?? 0);
        if ($project_id) update_post_meta($post_id, '_ap_task_project_id', $project_id);

        // Denormalize client id
        $client_id = $project_id ? (int)get_post_meta($project_id, '_ap_project_client_id', true) : 0;
        update_post_meta($post_id, '_ap_task_client_id', $client_id);

        update_post_meta($post_id, '_ap_task_status', sanitize_key($_POST['_ap_task_status'] ?? 'backlog'));

        $client_visible = isset($_POST['_ap_task_client_visible']) ? 1 : 0;
        update_post_meta($post_id, '_ap_task_client_visible', $client_visible);

        $start = self::datetime_local_to_mysql(sanitize_text_field($_POST['_ap_task_start_datetime'] ?? ''));
        $end   = self::datetime_local_to_mysql(sanitize_text_field($_POST['_ap_task_end_datetime'] ?? ''));
        if ($start) update_post_meta($post_id, '_ap_task_start_datetime', $start);
        if ($end) update_post_meta($post_id, '_ap_task_end_datetime', $end);

        break;
    }
  }

  // -----------------------------
  // Phase 1.1 task filters (kept short: status/client/project)
  // -----------------------------
  public static function tasks_admin_filters($post_type) {
    if ($post_type !== 'ap_task') return;

    $selected_client = isset($_GET['ap_client']) ? (int) $_GET['ap_client'] : 0;
    $selected_project = isset($_GET['ap_project']) ? (int) $_GET['ap_project'] : 0;
    $selected_status = isset($_GET['ap_status']) ? sanitize_key($_GET['ap_status']) : '';

    $clients = get_posts(['post_type'=>'ap_client','numberposts'=>-1,'orderby'=>'title','order'=>'ASC','post_status'=>['publish','draft']]);
    echo '<select name="ap_client" style="max-width:220px;">';
    echo '<option value="0">All clients</option>';
    foreach ($clients as $c) echo '<option value="'.esc_attr($c->ID).'" '.selected($selected_client, $c->ID, false).'>'.esc_html($c->post_title).'</option>';
    echo '</select>';

    $projects = get_posts(['post_type'=>'ap_project','numberposts'=>-1,'orderby'=>'title','order'=>'ASC','post_status'=>['publish','draft']]);
    echo '<select name="ap_project" style="max-width:220px; margin-left:6px;">';
    echo '<option value="0">All projects</option>';
    foreach ($projects as $p) echo '<option value="'.esc_attr($p->ID).'" '.selected($selected_project, $p->ID, false).'>'.esc_html($p->post_title).'</option>';
    echo '</select>';

    $statuses = self::get_enum('task_statuses');
    echo '<select name="ap_status" style="max-width:200px; margin-left:6px;">';
    echo '<option value="">All statuses</option>';
    foreach ($statuses as $st) echo '<option value="'.esc_attr($st).'" '.selected($selected_status, $st, false).'>'.esc_html($st).'</option>';
    echo '</select>';
  }

  public static function apply_tasks_admin_filters($query) {
    if (!is_admin() || !$query->is_main_query()) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== 'ap_task') return;

    $meta_query = [];

    if (!empty($_GET['ap_client'])) {
      $meta_query[] = ['key'=>'_ap_task_client_id','value'=>(string)(int)$_GET['ap_client'],'compare'=>'='];
    }
    if (!empty($_GET['ap_project'])) {
      $meta_query[] = ['key'=>'_ap_task_project_id','value'=>(string)(int)$_GET['ap_project'],'compare'=>'='];
    }
    if (!empty($_GET['ap_status'])) {
      $meta_query[] = ['key'=>'_ap_task_status','value'=>sanitize_key($_GET['ap_status']),'compare'=>'='];
    }

    if ($meta_query) $query->set('meta_query', $meta_query);
  }

  // -----------------------------
  // Phase 2: Blocks
  // -----------------------------
  public static function register_blocks() {
    // Editor script that registers blocks in Gutenberg
    wp_register_script(
      'agency-portal-blocks',
      plugins_url('blocks.js', __FILE__),
      ['wp-blocks','wp-element','wp-components','wp-block-editor','wp-i18n','wp-server-side-render'],
      '0.2.0',
      true
    );

    // Projects block
    register_block_type('agency-portal/projects', [
      'editor_script' => 'agency-portal-blocks',
      'render_callback' => [__CLASS__, 'render_block_projects'],
      'attributes' => [
        'title' => ['type'=>'string','default'=>'Your Projects'],
        'layout' => ['type'=>'string','default'=>'table'], // table|list
        'showStatus' => ['type'=>'boolean','default'=>true],
        'accent' => ['type'=>'string','default'=>''], // branding hook (no CSS assumptions)
      ],
      'supports' => [
        'anchor' => true,
        'color' => ['text'=>true,'background'=>true],
        'spacing' => ['padding'=>true,'margin'=>true],
        'typography' => ['fontSize'=>true],
      ],
    ]);

    // Tasks block
    register_block_type('agency-portal/tasks', [
      'editor_script' => 'agency-portal-blocks',
      'render_callback' => [__CLASS__, 'render_block_tasks'],
      'attributes' => [
        'title' => ['type'=>'string','default'=>'Your Tasks'],
        'layout' => ['type'=>'string','default'=>'table'], // table|list
        'showProject' => ['type'=>'boolean','default'=>true],
        'showDates' => ['type'=>'boolean','default'=>true],
        'showStatus' => ['type'=>'boolean','default'=>true],
        'showOnlyClientVisible' => ['type'=>'boolean','default'=>true],
      ],
      'supports' => [
        'anchor' => true,
        'color' => ['text'=>true,'background'=>true],
        'spacing' => ['padding'=>true,'margin'=>true],
        'typography' => ['fontSize'=>true],
      ],
    ]);

    // Task comments block
    register_block_type('agency-portal/task-comments', [
      'editor_script' => 'agency-portal-blocks',
      'render_callback' => [__CLASS__, 'render_block_task_comments'],
      'attributes' => [
        'title' => ['type'=>'string','default'=>'Task Comments'],
        'mode' => ['type'=>'string','default'=>'selected'], // selected|queryvar
        'taskId' => ['type'=>'number','default'=>0],
      ],
      'supports' => [
        'anchor' => true,
        'color' => ['text'=>true,'background'=>true],
        'spacing' => ['padding'=>true,'margin'=>true],
      ],
    ]);

    // Client profile block
    register_block_type('agency-portal/client-profile', [
      'editor_script' => 'agency-portal-blocks',
      'render_callback' => [__CLASS__, 'render_block_client_profile'],
      'attributes' => [
        'title' => ['type'=>'string','default'=>'Your Profile'],
        'showContact' => ['type'=>'boolean','default'=>true],
        'showBilling' => ['type'=>'boolean','default'=>false],
      ],
      'supports' => [
        'anchor' => true,
        'color' => ['text'=>true,'background'=>true],
        'spacing' => ['padding'=>true,'margin'=>true],
      ],
    ]);
  }

  // -----------------------------
  // Phase 2: Secure client context
  // -----------------------------
  public static function get_current_client_id(): int {
    $user_id = get_current_user_id();
    if (!$user_id) return 0;

    // Option A: store user meta pointing to client
    $client_id = (int) get_user_meta($user_id, '_ap_client_id', true);
    if ($client_id && get_post_type($client_id) === 'ap_client') return $client_id;

    // Option B: client post meta linking to WP user
    $clients = get_posts([
      'post_type' => 'ap_client',
      'numberposts' => 1,
      'post_status' => ['publish','draft'],
      'meta_query' => [[
        'key' => '_ap_client_wp_user_id',
        'value' => (string)$user_id,
        'compare' => '=',
      ]],
    ]);
    if ($clients) return (int)$clients[0]->ID;

    return 0;
  }

  public static function current_user_is_client(): bool {
    $user = wp_get_current_user();
    if (!$user || !$user->ID) return false;
    return in_array(self::ROLE_CLIENT, (array)$user->roles, true);
  }

  // -----------------------------
  // Phase 2: Render blocks
  // -----------------------------
  public static function render_block_projects($attrs) {
    if (!is_user_logged_in()) return '<p>You must be logged in.</p>';
    if (!self::current_user_is_client()) return '<p>Access denied.</p>';

    $client_id = self::get_current_client_id();
    if (!$client_id) return '<p>No client is linked to your account. Please contact the agency.</p>';

    $title = esc_html($attrs['title'] ?? 'Your Projects');
    $layout = sanitize_key($attrs['layout'] ?? 'table');
    $showStatus = !empty($attrs['showStatus']);

    $projects = get_posts([
      'post_type' => 'ap_project',
      'numberposts' => -1,
      'orderby' => 'title',
      'order' => 'ASC',
      'post_status' => ['publish','draft'],
      'meta_query' => [
        ['key'=>'_ap_project_client_id','value'=>(string)$client_id,'compare'=>'='],
        // Only expose client_visible projects
        ['key'=>'_ap_project_visibility','value'=>'client_visible','compare'=>'='],
      ],
    ]);

    $out = '<div class="ap-block ap-projects">';
    $out .= '<h3>'.$title.'</h3>';

    if (!$projects) {
      $out .= '<p>No projects available.</p></div>';
      return $out;
    }

    if ($layout === 'list') {
      $out .= '<ul>';
      foreach ($projects as $p) {
        $status = esc_html(get_post_meta($p->ID, '_ap_project_status', true));
        $out .= '<li><strong>'.esc_html($p->post_title).'</strong>';
        if ($showStatus) $out .= ' <span class="ap-pill">('.$status.')</span>';
        $out .= '</li>';
      }
      $out .= '</ul>';
    } else {
      $out .= '<table class="ap-table" style="width:100%; border-collapse:collapse;">';
      $out .= '<thead><tr>';
      $out .= '<th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;">Project</th>';
      if ($showStatus) $out .= '<th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;">Status</th>';
      $out .= '</tr></thead><tbody>';
      foreach ($projects as $p) {
        $status = esc_html(get_post_meta($p->ID, '_ap_project_status', true));
        $out .= '<tr>';
        $out .= '<td style="padding:8px; border-bottom:1px solid #eee;">'.esc_html($p->post_title).'</td>';
        if ($showStatus) $out .= '<td style="padding:8px; border-bottom:1px solid #eee;">'.$status.'</td>';
        $out .= '</tr>';
      }
      $out .= '</tbody></table>';
    }

    $out .= '</div>';
    return $out;
  }

  public static function render_block_tasks($attrs) {
    if (!is_user_logged_in()) return '<p>You must be logged in.</p>';
    if (!self::current_user_is_client()) return '<p>Access denied.</p>';

    $client_id = self::get_current_client_id();
    if (!$client_id) return '<p>No client is linked to your account. Please contact the agency.</p>';

    $title = esc_html($attrs['title'] ?? 'Your Tasks');
    $layout = sanitize_key($attrs['layout'] ?? 'table');
    $showProject = !empty($attrs['showProject']);
    $showDates = !empty($attrs['showDates']);
    $showStatus = !empty($attrs['showStatus']);
    $onlyClientVisible = !empty($attrs['showOnlyClientVisible']);

    // Only show tasks for client; also only those tasks explicitly marked client_visible (internal tasks excluded)
    $meta_query = [
      ['key'=>'_ap_task_client_id','value'=>(string)$client_id,'compare'=>'='],
    ];
    if ($onlyClientVisible) {
      $meta_query[] = ['key'=>'_ap_task_client_visible','value'=>'1','compare'=>'='];
    }

    $tasks = get_posts([
      'post_type' => 'ap_task',
      'numberposts' => -1,
      'orderby' => 'meta_value',
      'meta_key' => '_ap_task_start_datetime',
      'order' => 'ASC',
      'post_status' => ['publish','draft'],
      'meta_query' => $meta_query,
    ]);

    $out = '<div class="ap-block ap-tasks">';
    $out .= '<h3>'.$title.'</h3>';

    if (!$tasks) {
      $out .= '<p>No tasks available.</p></div>';
      return $out;
    }

    // Optional: allow clicking task to load comments block by query var (?ap_task=ID)
    $portal_url = esc_url(self::current_url_without_task_param());

    if ($layout === 'list') {
      $out .= '<ul>';
      foreach ($tasks as $t) {
        $status = esc_html(get_post_meta($t->ID, '_ap_task_status', true));
        $project = (int)get_post_meta($t->ID, '_ap_task_project_id', true);
        $project_title = $project ? get_the_title($project) : '';
        $start = get_post_meta($t->ID, '_ap_task_start_datetime', true);
        $end = get_post_meta($t->ID, '_ap_task_end_datetime', true);

        $task_link = esc_url(add_query_arg('ap_task', $t->ID, $portal_url));

        $out .= '<li>';
        $out .= '<a href="'.$task_link.'"><strong>'.esc_html($t->post_title).'</strong></a>';
        if ($showStatus) $out .= ' <span class="ap-pill">('.$status.')</span>';
        if ($showProject && $project_title) $out .= '<div><small>Project: '.esc_html($project_title).'</small></div>';
        if ($showDates && $start && $end) $out .= '<div><small>'.esc_html($start).' → '.esc_html($end).'</small></div>';
        $out .= '</li>';
      }
      $out .= '</ul>';
    } else {
      $out .= '<table class="ap-table" style="width:100%; border-collapse:collapse;">';
      $out .= '<thead><tr>';
      $out .= '<th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;">Task</th>';
      if ($showProject) $out .= '<th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;">Project</th>';
      if ($showStatus) $out .= '<th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;">Status</th>';
      if ($showDates) $out .= '<th style="text-align:left; border-bottom:1px solid #ddd; padding:8px;">Dates</th>';
      $out .= '</tr></thead><tbody>';

      foreach ($tasks as $t) {
        $status = esc_html(get_post_meta($t->ID, '_ap_task_status', true));
        $project = (int)get_post_meta($t->ID, '_ap_task_project_id', true);
        $project_title = $project ? get_the_title($project) : '';
        $start = get_post_meta($t->ID, '_ap_task_start_datetime', true);
        $end = get_post_meta($t->ID, '_ap_task_end_datetime', true);

        $task_link = esc_url(add_query_arg('ap_task', $t->ID, $portal_url));

        $out .= '<tr>';
        $out .= '<td style="padding:8px; border-bottom:1px solid #eee;"><a href="'.$task_link.'">'.esc_html($t->post_title).'</a></td>';
        if ($showProject) $out .= '<td style="padding:8px; border-bottom:1px solid #eee;">'.esc_html($project_title).'</td>';
        if ($showStatus) $out .= '<td style="padding:8px; border-bottom:1px solid #eee;">'.$status.'</td>';
        if ($showDates) $out .= '<td style="padding:8px; border-bottom:1px solid #eee;">'.esc_html($start).' → '.esc_html($end).'</td>';
        $out .= '</tr>';
      }

      $out .= '</tbody></table>';
    }

    $out .= '<p><small>Select a task to view and add comments.</small></p>';
    $out .= '</div>';

    return $out;
  }

  public static function render_block_task_comments($attrs) {
    if (!is_user_logged_in()) return '<p>You must be logged in.</p>';
    if (!self::current_user_is_client()) return '<p>Access denied.</p>';

    $client_id = self::get_current_client_id();
    if (!$client_id) return '<p>No client is linked to your account. Please contact the agency.</p>';

    $title = esc_html($attrs['title'] ?? 'Task Comments');
    $mode = sanitize_key($attrs['mode'] ?? 'selected');
    $task_id = (int)($attrs['taskId'] ?? 0);

    // Mode "queryvar" uses ?ap_task=123
    if ($mode === 'queryvar') {
      $task_id = isset($_GET['ap_task']) ? (int)$_GET['ap_task'] : 0;
    } elseif ($mode === 'selected') {
      // Default: if taskId set use it; otherwise use queryvar
      if (!$task_id) $task_id = isset($_GET['ap_task']) ? (int)$_GET['ap_task'] : 0;
    }

    if (!$task_id || get_post_type($task_id) !== 'ap_task') {
      return '<div class="ap-block ap-task-comments"><h3>'.$title.'</h3><p>Select a task to view comments.</p></div>';
    }

    // Security: task must belong to client AND be client_visible
    if (!self::client_can_view_task($client_id, $task_id)) {
      return '<div class="ap-block ap-task-comments"><h3>'.$title.'</h3><p>Access denied.</p></div>';
    }

    // Ensure comments open on tasks
    if (!comments_open($task_id)) {
      // In Phase 2 we allow comments; open them by default for portal usage
      // (Optional policy: let agency close comments per task later)
    }

    $task_title = esc_html(get_the_title($task_id));
    $task_status = esc_html(get_post_meta($task_id, '_ap_task_status', true));
    $task_desc = apply_filters('the_content', get_post_field('post_content', $task_id));

    ob_start();
    echo '<div class="ap-block ap-task-comments">';
    echo '<h3>'.$title.'</h3>';
    echo '<div class="ap-task-panel">';
    echo '<h4 style="margin-top:0;">'.$task_title.'</h4>';
    echo '<p><strong>Status:</strong> '.$task_status.'</p>';
    echo '<div class="ap-task-desc">'.$task_desc.'</div>';
    echo '</div>';

    // Render comment list + form
    $comments = get_comments(['post_id' => $task_id, 'status' => 'approve']);
    if ($comments) {
      echo '<h4>Comments</h4>';
      echo '<ol class="comment-list" style="padding-left:18px;">';
      wp_list_comments(['style'=>'ol'], $comments);
      echo '</ol>';
    } else {
      echo '<p>No comments yet.</p>';
    }

    echo '<h4>Leave a comment</h4>';
    comment_form([
      'title_reply' => '',
      'comment_notes_before' => '',
      'comment_notes_after' => '',
      'logged_in_as' => '',
      'comment_field' => '<p class="comment-form-comment"><textarea id="comment" name="comment" cols="45" rows="5" required="required"></textarea></p>',
    ], $task_id);

    echo '</div>';
    return ob_get_clean();
  }

  public static function render_block_client_profile($attrs) {
    if (!is_user_logged_in()) return '<p>You must be logged in.</p>';
    if (!self::current_user_is_client()) return '<p>Access denied.</p>';

    $client_id = self::get_current_client_id();
    if (!$client_id) return '<p>No client is linked to your account. Please contact the agency.</p>';

    $title = esc_html($attrs['title'] ?? 'Your Profile');
    $showContact = !empty($attrs['showContact']);
    $showBilling = !empty($attrs['showBilling']);

    $name = get_post_meta($client_id, '_ap_primary_contact_name', true);
    $email = get_post_meta($client_id, '_ap_primary_contact_email', true);
    $code = get_post_meta($client_id, '_ap_client_code', true);
    $status = get_post_meta($client_id, '_ap_client_status', true);

    $out = '<div class="ap-block ap-client-profile">';
    $out .= '<h3>'.$title.'</h3>';
    $out .= '<ul style="margin:0; padding-left:18px;">';
    $out .= '<li><strong>Client:</strong> '.esc_html(get_the_title($client_id)).'</li>';
    if ($code) $out .= '<li><strong>Code:</strong> '.esc_html($code).'</li>';
    if ($status) $out .= '<li><strong>Status:</strong> '.esc_html($status).'</li>';
    $out .= '</ul>';

    if ($showContact) {
      $out .= '<h4>Contact</h4>';
      $out .= '<ul style="margin:0; padding-left:18px;">';
      if ($name) $out .= '<li><strong>Name:</strong> '.esc_html($name).'</li>';
      if ($email) $out .= '<li><strong>Email:</strong> '.esc_html($email).'</li>';
      $out .= '</ul>';
    }

    if ($showBilling) {
      $out .= '<p><em>Billing fields can be added in later phases (quotes/invoices).</em></p>';
    }

    $out .= '</div>';
    return $out;
  }

  // -----------------------------
  // Comment permission hardening
  // -----------------------------
  public static function guard_task_comment_access($comment_post_ID) {
    $post_id = (int)$comment_post_ID;
    if (get_post_type($post_id) !== 'ap_task') return;

    // Must be logged in
    if (!is_user_logged_in()) {
      wp_die('You must be logged in to comment.');
    }

    // Must be client role
    if (!self::current_user_is_client()) {
      wp_die('Access denied.');
    }

    $client_id = self::get_current_client_id();
    if (!$client_id || !self::client_can_view_task($client_id, $post_id)) {
      wp_die('Access denied.');
    }
  }

  private static function client_can_view_task(int $client_id, int $task_id): bool {
    $task_client_id = (int)get_post_meta($task_id, '_ap_task_client_id', true);
    if ($task_client_id !== $client_id) return false;

    // Task must be explicitly client visible
    $vis = get_post_meta($task_id, '_ap_task_client_visible', true);
    if ((string)$vis !== '1') return false;

    // Project must be client_visible as well (extra hardening)
    $project_id = (int)get_post_meta($task_id, '_ap_task_project_id', true);
    if ($project_id) {
      $pvis = get_post_meta($project_id, '_ap_project_visibility', true);
      if ($pvis !== 'client_visible') return false;
    }

    return true;
  }

  // -----------------------------
  // Helpers
  // -----------------------------
  private static function select_html(string $name, array $options, string $selected): string {
    $html = '<select name="'.esc_attr($name).'" class="widefat">';
    foreach ($options as $opt) {
      $html .= '<option value="'.esc_attr($opt).'" '.selected($selected, $opt, false).'>'.esc_html($opt).'</option>';
    }
    $html .= '</select>';
    return $html;
  }

  private static function mysql_to_datetime_local(?string $mysql): string {
    if (!$mysql) return '';
    $ts = strtotime($mysql);
    if (!$ts) return '';
    return date('Y-m-d\TH:i', $ts);
  }

  private static function datetime_local_to_mysql(string $local): string {
    $local = trim($local);
    if ($local === '') return '';
    $local = str_replace('T', ' ', $local);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $local)) return '';
    return $local . ':00';
  }

  private static function current_url_without_task_param(): string {
    $scheme = is_ssl() ? 'https' : 'http';
    $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $url = remove_query_arg('ap_task', $url);
    return $url;
  }
}

Agency_Portal_Plugin::init();