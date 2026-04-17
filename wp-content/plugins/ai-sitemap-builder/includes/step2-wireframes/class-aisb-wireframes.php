<?php

if (!defined('ABSPATH')) exit;

class AISB_Wireframes {

  /** @var AISB_Template_Library */
  private $tpl_lib;
  /** @var AISB_Wireframe_Compiler */
  private $compiler;
  /** @var AISB_Wireframes_Bricks */
  private $bricks;
  /** @var AISB_Wireframes_AI */
  private $ai;

  public function __construct(AISB_Template_Library $tpl_lib, AISB_Wireframe_Compiler $compiler) {
    $this->tpl_lib = $tpl_lib;
    $this->compiler = $compiler;
    $this->bricks = new AISB_Wireframes_Bricks();
    $this->ai = new AISB_Wireframes_AI();
  }

  public function init(): void {
    $this->tpl_lib->init();

    // Shortcode + assets
    add_action('init', [$this, 'register_shortcode']);
    add_action('template_redirect', [$this, 'render_bricks_preview']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    // AJAX
    add_action('wp_ajax_aisb_get_wireframe_page', [$this, 'ajax_get_wireframe_page']);
    add_action('wp_ajax_aisb_generate_wireframe_page', [$this, 'ajax_generate_wireframe_page']);
    add_action('wp_ajax_aisb_update_wireframe_page', [$this, 'ajax_update_wireframe_page']);
    add_action('wp_ajax_aisb_shuffle_section_layout', [$this, 'ajax_shuffle_section_layout']);
    add_action('wp_ajax_aisb_replace_section_type', [$this, 'ajax_replace_section_type']);
    add_action('wp_ajax_aisb_compile_wireframe_page', [$this, 'ajax_compile_wireframe_page']);
    add_action('wp_ajax_aisb_get_bricks_section_types', [$this, 'ajax_get_bricks_section_types']);
    add_action('wp_ajax_aisb_save_section_text', [$this, 'ajax_save_section_text']);
  }

  public function register_shortcode(): void {
    add_shortcode('ai_wireframes', [$this, 'render_shortcode']);
  }

  public function render_bricks_preview(): void {
    if (!isset($_GET['aisb_bricks_preview'])) return;
    if (!is_user_logged_in()) {
      wp_die('Unauthorized');
    }
    $id = (int)$_GET['aisb_bricks_preview'];
    if (!$id) wp_die('No valid Bricks ID provided');

    $post = get_post($id);
    if (!$post) wp_die('Post not found');

    // Zowel bricks_template als ai_wireframe posts toestaan voor preview
    $allowed_types = ['bricks_template', 'ai_wireframe'];
    if (!in_array($post->post_type, $allowed_types, true)) {
      wp_die('Invalid post type for preview');
    }

    show_admin_bar(false);

    // ─── Navigatie-menu vullen met sitemap-pagina's ─────────
    // Bricks nav-menu elementen verwijzen naar een WP menu.
    // Als dat menu niet bestaat of leeg is, is de navigatie leeg.
    // Hier maken we een tijdelijk menu aan met de pagina's uit de sitemap.
    $this->setup_wireframe_nav_menu($id, $post->post_type);

    // Set up post context BEFORE CSS generation so Bricks knows which elements need CSS
    global $post;
    $post = get_post($id);
    setup_postdata($post);

    if (function_exists('bricks_enqueue_scripts')) {
      bricks_enqueue_scripts();
    }

    // Generate element-specific CSS (flex-direction, grid, etc.) for the template
    if (class_exists('\Bricks\Assets')) {
      $elements = get_post_meta($id, '_bricks_page_content_2', true);
      if (is_array($elements) && !empty($elements)) {
        \Bricks\Assets::$post_id = $id;
        \Bricks\Assets::generate_css_from_elements($elements, 'content');
      }
    }

    wp_enqueue_style(
      'aisb-wireframes-preview',
      AISB_PLUGIN_URL . 'assets/wireframes-preview.css',
      [],
      AISB_VERSION
    );
    wp_enqueue_script(
      'aisb-wireframes-preview',
      AISB_PLUGIN_URL . 'assets/wireframes-preview.js',
      [],
      AISB_VERSION,
      true
    );
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
      <meta charset="<?php bloginfo('charset'); ?>">
      <meta name="viewport" content="width=1200, initial-scale=1">
      <script>
      /* Spoof viewport width BEFORE any Bricks JS runs so all breakpoint checks see 1200px */
      Object.defineProperty(window, 'innerWidth', { get: function(){ return 1200; }, configurable: true });
      Object.defineProperty(document.documentElement, 'clientWidth', { get: function(){ return 1200; }, configurable: true });
      </script>
      <?php wp_head(); ?>
    </head>
    <body <?php body_class(); ?>>
      <style id="aisb-force-desktop">
        /* Override ALL Bricks responsive breakpoints to force desktop layout */
        @media (max-width: 1320px) {
          .brxe-section, .brxe-container, .brxe-block, .brxe-div { flex-wrap: nowrap !important; }
        }
        @media (max-width: 991px) {
          .brxe-section, .brxe-container, .brxe-block, .brxe-div { flex-wrap: nowrap !important; }
          [class*="brxe-"] { width: unset !important; max-width: unset !important; flex-basis: unset !important; flex-direction: unset !important; }
        }
        @media (max-width: 767px) {
          .brxe-section, .brxe-container, .brxe-block, .brxe-div { flex-wrap: nowrap !important; }
          [class*="brxe-"] { width: unset !important; max-width: unset !important; flex-basis: unset !important; flex-direction: unset !important; }
        }
        @media (max-width: 478px) {
          .brxe-section, .brxe-container, .brxe-block, .brxe-div { flex-wrap: nowrap !important; }
          [class*="brxe-"] { width: unset !important; max-width: unset !important; flex-basis: unset !important; flex-direction: unset !important; }
        }
      </style>
      <div class="aisb-bricks-preview-wrap" id="aisb-preview">
        <?php
        if ($post->post_type === 'ai_wireframe') {
          if (class_exists('\Bricks\Frontend')) {
            $elements = get_post_meta($id, '_bricks_page_content_2', true);
            if (is_array($elements) && !empty($elements)) {
              echo \Bricks\Frontend::render_data($elements);
            } else {
              echo '<p style="padding:20px;color:#999;">Geen Bricks elementen gevonden in deze AI wireframe.</p>';
            }
          } else {
            echo '<p style="padding:20px;color:#999;">Bricks Builder is vereist om AI wireframes te bekijken.</p>';
          }
        } else {
          // Bricks template: header/footer/section via shortcode
          echo do_shortcode('[bricks_template id="' . $id . '"]');
        }
        ?>
      </div>
      <?php wp_footer(); ?>
      <script>
      /* Force desktop: strip max-width media queries from all stylesheets, continuously */
      (function(){
        function stripMaxWidth(){
          try {
            for(var i=0;i<document.styleSheets.length;i++){
              var s=document.styleSheets[i];
              try{var r=s.cssRules||s.rules;}catch(e){continue;}
              if(!r)continue;
              for(var j=r.length-1;j>=0;j--){
                if(r[j].type===CSSRule.MEDIA_RULE&&r[j].conditionText&&r[j].conditionText.indexOf('max-width')!==-1){
                  s.deleteRule(j);
                }
              }
            }
          }catch(e){}
        }
        /* Run immediately */
        stripMaxWidth();
        /* Re-run when any existing <link> stylesheet finishes loading */
        document.querySelectorAll('link[rel="stylesheet"]').forEach(function(link){
          link.addEventListener('load', function(){ setTimeout(stripMaxWidth,0); });
        });
        /* Watch for new <style>/<link> elements added later */
        new MutationObserver(function(mutations){
          for(var m=0;m<mutations.length;m++){
            for(var n=0;n<mutations[m].addedNodes.length;n++){
              var node=mutations[m].addedNodes[n];
              if(node.tagName==='STYLE'){ setTimeout(stripMaxWidth,0); return; }
              if(node.tagName==='LINK'){
                node.addEventListener('load', function(){ setTimeout(stripMaxWidth,0); });
                return;
              }
            }
          }
        }).observe(document.documentElement,{childList:true,subtree:true});
        /* Safety net: re-run a few times during page load */
        setTimeout(stripMaxWidth, 500);
        setTimeout(stripMaxWidth, 1500);
        setTimeout(stripMaxWidth, 3000);
        window.addEventListener('load', function(){ setTimeout(stripMaxWidth, 100); });
      })();
      </script>
    </body>
    </html>
    <?php
    exit;
  }

  /**
   * Maakt een tijdelijk WP navigatie-menu aan met pagina's uit de sitemap
   * en zorgt dat alle nav-menu's in de Bricks preview dit menu gebruiken.
   */
  private function setup_wireframe_nav_menu(int $post_id, string $post_type): void {
    // Alleen voor ai_wireframe posts (die hebben project metadata)
    // Voor gewone bricks_template posts: probeer het toch via alle ai_wireframe posts
    $project_id = 0;
    if ($post_type === 'ai_wireframe') {
      $project_id = (int) get_post_meta($post_id, '_aisb_project_id', true);
    }
    if (!$project_id) return;

    // Haal de sitemap op via het project
    $sitemap_id = (int) get_post_meta($project_id, 'aisb_latest_sitemap_id', true);
    if (!$sitemap_id) return;

    $sitemap_json = get_post_meta($sitemap_id, 'aisb_sitemap_json', true);
    $sitemap_data = $sitemap_json ? json_decode((string) $sitemap_json, true) : [];

    // Pagina's uit de sitemap halen
    $sitemap_pages = [];
    if (!empty($sitemap_data['sitemap']) && is_array($sitemap_data['sitemap'])) {
      $sitemap_pages = $sitemap_data['sitemap'];
    } elseif (!empty($sitemap_data['pages']) && is_array($sitemap_data['pages'])) {
      $sitemap_pages = $sitemap_data['pages'];
    }

    if (empty($sitemap_pages)) return;

    // Alleen top-level pagina's (geen child pages) voor de hoofdnavigatie
    $nav_items = [];
    foreach ($sitemap_pages as $page) {
      $parent = $page['parent_slug'] ?? $page['parent'] ?? '';
      // Alleen top-level pagina's nemen (max 7 items)
      if (!empty($parent)) continue;
      $title = $page['nav_label'] ?? $page['page_title'] ?? $page['title'] ?? '';
      if (!$title) continue;
      $nav_items[] = $title;
      if (count($nav_items) >= 7) break;
    }

    if (empty($nav_items)) {
      // Fallback: pak de eerste 6 pagina's ongeacht parent
      foreach ($sitemap_pages as $page) {
        $title = $page['nav_label'] ?? $page['page_title'] ?? $page['title'] ?? '';
        if (!$title) continue;
        $nav_items[] = $title;
        if (count($nav_items) >= 6) break;
      }
    }

    if (empty($nav_items)) return;

    // Maak het menu aan (of hergebruik bestaand)
    $menu_name = 'AISB Wireframe Nav ' . $project_id;
    $menu = wp_get_nav_menu_object($menu_name);
    if (!$menu) {
      $menu_id = wp_create_nav_menu($menu_name);
      if (is_wp_error($menu_id)) return;
    } else {
      $menu_id = $menu->term_id;
      // Bestaande items verwijderen voor een schone update
      $existing = wp_get_nav_menu_items($menu_id);
      if ($existing) {
        foreach ($existing as $item) {
          wp_delete_post($item->ID, true);
        }
      }
    }

    // Navigatie-items toevoegen
    $position = 0;
    foreach ($nav_items as $title) {
      $position++;
      wp_update_nav_menu_item($menu_id, 0, [
        'menu-item-title'    => sanitize_text_field($title),
        'menu-item-url'      => '#',
        'menu-item-status'   => 'publish',
        'menu-item-position' => $position,
        'menu-item-type'     => 'custom',
      ]);
    }

    // Filter: forceer dit menu voor alle wp_nav_menu calls in deze preview
    add_filter('wp_nav_menu_args', function ($args) use ($menu_id) {
      $args['menu'] = $menu_id;
      return $args;
    }, 9999);

    // Filter: ook het Bricks nav-menu element forceren (Bricks gebruikt soms theme_location)
    add_filter('pre_wp_nav_menu', function ($output, $args) use ($menu_id) {
      if ($output !== null) return $output;
      return null;
    }, 10, 2);

    // Bricks nav-menu element settings overschrijven
    add_filter('bricks/element/settings', function ($settings, $element) use ($menu_id) {
      // $element kan een object of array zijn, afhankelijk van Bricks versie
      $name = '';
      if (is_object($element) && isset($element->name)) {
        $name = $element->name;
      } elseif (is_object($element) && method_exists($element, 'get_name')) {
        $name = $element->get_name();
      } elseif (is_array($element) && isset($element['name'])) {
        $name = $element['name'];
      }
      if ($name === 'nav-menu' || $name === 'navigation') {
        $settings['menu'] = (string) $menu_id;
      }
      return $settings;
    }, 10, 2);
  }

  public function enqueue_assets(): void {
    $is_step2 = ((int)($_GET['aisb_step'] ?? 0) === 2);
    $has_project_ctx = isset($_GET['aisb_project']) && isset($_GET['aisb_sitemap']);

    $is_wireframes_shortcode = $this->current_page_has_shortcode('ai_wireframes');
    $is_builder_shortcode = $this->current_page_has_shortcode('ai_sitemap_builder');
    $is_step2_in_builder = $is_step2 && $has_project_ctx;

    if (!$is_wireframes_shortcode && !$is_step2_in_builder && !$is_builder_shortcode) return;

    wp_enqueue_style(
      'aisb-wireframes-style',
      AISB_PLUGIN_URL . 'assets/wireframes.css',
      [],
      AISB_VERSION
    );

    // Wireframe JS modules — loaded in dependency order
    $wf_modules = [
      'state',
      'helpers',
      'canvas',
      'whiteboard',
      'expanded',
      'sections',
      'sitemap',
      'generate',
      'actions',
      'init',
    ];
    $prev = [];
    foreach ($wf_modules as $mod) {
      $handle = "aisb-wf-{$mod}";
      wp_enqueue_script(
        $handle,
        AISB_PLUGIN_URL . "assets/js/wireframes/{$mod}.js",
        $prev,
        AISB_VERSION,
        true
      );
      $prev = [$handle];
    }
    wp_localize_script('aisb-wf-state', 'AISB_WF', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'previewUrl' => home_url('/?aisb_bricks_preview='),
      'nonce'   => wp_create_nonce('aisb_wf_nonce'),
      'coreNonce' => wp_create_nonce('aisb_nonce_action'),
      'sectionTypes' => $this->bricks->get_all_section_types(),
    ]);
  }

  public function render_shortcode($atts = [], $content = null): string {
    if (!is_user_logged_in()) {
      return '<div class="aisb-wrap"><div class="aisb-card"><p>You must be logged in to use wireframes.</p></div></div>';
    }

    $project_id = isset($_GET['aisb_project']) ? (int)$_GET['aisb_project'] : 0;
    $sitemap_id = isset($_GET['aisb_sitemap']) ? (int)$_GET['aisb_sitemap'] : 0;

    ob_start();
    ?>
    <div class="aisb-wrap" data-aisb-wireframes
         data-project-id="<?php echo esc_attr($project_id); ?>"
         data-sitemap-id="<?php echo esc_attr($sitemap_id); ?>">
      <div class="aisb-card">
        <div class="aisb-wf-head">
          <div>
            <h2 class="aisb-title">Wireframes</h2>
            <p class="aisb-subtitle">Relume-like preview · Brixies sections · fast skeleton rendering</p>
          </div>
          <div class="aisb-wf-top-actions">
            <a class="aisb-btn-secondary" href="<?php echo esc_url(remove_query_arg(['aisb_step'])); ?>">Back to sitemap</a>
          </div>
        </div>

        <?php if (!$project_id || !$sitemap_id) : ?>
          <div class="aisb-wf-no-project">
            <p class="aisb-wf-no-project-msg">Please select one of your projects below to start generating wireframes.</p>
            <div class="aisb-wf-no-project-inner">
              <?php echo do_shortcode('[my-projects title=""]'); ?>
            </div>
          </div>
        <?php else : ?>

        <!-- Toolbar -->
        <div class="aisb-wf-toolbar">
          <div class="aisb-wf-toolbar-right">
            <button class="aisb-btn generate-wireframe__all" type="button" data-aisb-wf-generate-all>Generate all</button>
            <button class="aisb-btn" type="button" data-aisb-wf-save-all>Save all</button>
            <button class="aisb-btn" type="button" data-aisb-wf-something>Style your wireframes</button>
          </div>
        </div>

        <div class="aisb-wf-status" data-aisb-wf-status></div>

        <!-- Whiteboard -->
        <div class="aisb-wf-whiteboard" data-aisb-wf-whiteboard></div>

        <!-- Expanded page panel (hidden by default) -->
        <div class="aisb-wf-expanded" data-aisb-wf-expanded>
          <div class="aisb-wf-expanded-head">
            <div>
              <div class="aisb-wf-canvas-title" data-aisb-wf-page-title></div>
              <div class="aisb-wf-muted" data-aisb-wf-page-sub></div>
            </div>
            <div class="aisb-wf-actions">
              <button class="aisb-btn-secondary" type="button" data-aisb-wf-generate>Generate wireframe</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-wf-shuffle-page>Shuffle unlocked</button>
              <button class="aisb-btn" type="button" data-aisb-wf-save>Save</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-wf-compile>Compile JSON</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-wf-close-expanded>✕ Close</button>
            </div>
          </div>
          <div class="aisb-wf-sections" data-aisb-wf-sections></div>
          <details class="aisb-wf-raw">
            <summary>Compiled Bricks JSON (latest)</summary>
            <pre class="aisb-pre" data-aisb-wf-compiled></pre>
          </details>
        </div>

        <!-- Hidden legacy elements for JS compatibility -->
        <div class="aisb-wf-legacy-pages" data-aisb-wf-pages></div>

        <!-- Templates -->
        <template data-tpl="page-card">
          <div class="aisb-wf-page-card" data-wb-page>
            <div class="aisb-wf-page-card-head">
              <div>
                <div class="aisb-wf-page-card-title"></div>
                <div class="aisb-wf-page-card-slug"></div>
              </div>
              <span class="aisb-wf-page-card-badge"></span>
            </div>
            <div class="aisb-wf-page-card-body">
              <div class="aisb-wf-page-card-sections"></div>
            </div>
          </div>
        </template>

        <template data-tpl="section-card">
          <div class="aisb-wf-section" data-uuid>
            <div class="aisb-wf-section-toolbar">
              <button class="aisb-wf-tbtn" data-act="up" title="Move up">↑</button>
              <button class="aisb-wf-tbtn" data-act="down" title="Move down">↓</button>
              <button class="aisb-wf-tbtn" data-act="shuffle" title="Shuffle layout">⟳</button>
              <button class="aisb-wf-tbtn" data-act="lock" title="Lock">🔒</button>
              <button class="aisb-wf-tbtn" data-act="edit" title="Edit text">✏️</button>
              <button class="aisb-wf-tbtn" data-act="dup" title="Duplicate">⧉</button>
              <button class="aisb-wf-tbtn aisb-wf-tbtn-del" data-act="del" title="Delete">✕</button>
            </div>
            <div class="aisb-wf-body"></div>
          </div>
        </template>

        <template data-tpl="section-label">
          <div class="aisb-wf-section-label">
            <span class="aisb-wf-section-label-type"></span>
            <span class="aisb-wf-section-label-badge"></span>
          </div>
        </template>

        <?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  /* ------------------- Data access ------------------- */

  private function table_wireframes(): string {
    global $wpdb;
    return $wpdb->prefix . 'aisb_wireframes';
  }

  private function require_login(): void {
    if (!is_user_logged_in()) {
      wp_send_json_error(['message' => 'Not logged in'], 401);
    }
  }

  private function check_nonce(): void {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $ok_wf = $nonce && wp_verify_nonce($nonce, 'aisb_wf_nonce');
    $ok_core = $nonce && wp_verify_nonce($nonce, 'aisb_nonce_action');
    if (!$ok_wf && !$ok_core) {
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

  private function get_or_create_wireframe_row(int $project_id, int $sitemap_version_id, string $page_slug): array {
    global $wpdb;
    $table = $this->table_wireframes();
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug=%s",
      $project_id, $sitemap_version_id, $page_slug
    ), ARRAY_A);
    if ($row) return $row;

    $now = current_time('mysql');
    $model = [
      'page' => ['slug' => $page_slug, 'title' => ucfirst(str_replace('-', ' ', $page_slug))],
      'pattern' => 'generic',
      'sections' => [],
    ];
    $wpdb->insert($table, [
      'project_id' => $project_id,
      'sitemap_version_id' => $sitemap_version_id,
      'page_slug' => $page_slug,
      'model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES),
      'compiled_bricks_json' => null,
      'created_by' => get_current_user_id(),
      'created_at' => $now,
      'updated_at' => $now,
    ], ['%d','%d','%s','%s','%s','%d','%s','%s']);

    $id = (int)$wpdb->insert_id;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A) ?: [];
  }

  /* ------------------- AJAX endpoints ------------------- */

  public function ajax_get_wireframe_page(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug) wp_send_json_error(['message' => 'Missing params'], 400);

    $row = $this->get_or_create_wireframe_row($project_id, $sitemap_version_id, $page_slug);
    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) $model = [];
    wp_send_json_success(['wireframe' => $model]);
  }

  public function ajax_generate_wireframe_page(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug) wp_send_json_error(['message' => 'Missing params'], 400);

    $pattern = $this->detect_pattern($page_slug);
    $types = $this->patterns()[$pattern];
    $model = [
      'page' => ['slug' => $page_slug, 'title' => ucfirst(str_replace('-', ' ', $page_slug))],
      'pattern' => $pattern,
      'sections' => [],
    ];

    $bricks_by_type   = $this->bricks->get_bricks_templates_by_type();
    $used_bricks_ids  = [];
    $used_layout_keys = [];

    // Hergebruik dezelfde header/footer template over alle pagina's
    $shared_templates = $this->find_shared_templates($project_id, $sitemap_version_id, $page_slug);

    foreach ($types as $t) {
      // Header/footer: consistente template hergebruiken als die al bestaat
      if (in_array($t, ['header', 'footer'], true) && !empty($shared_templates[$t])) {
        $shared = $shared_templates[$t];
        $used_bricks_ids[] = (int) $shared['bricks_template_id'];
        $model['sections'][] = [
          'uuid'                  => wp_generate_uuid4(),
          'type'                  => $t,
          'layout_key'            => 'bricks_' . $shared['bricks_template_id'],
          'bricks_template_id'    => $shared['bricks_template_id'],
          'bricks_template_title' => $shared['bricks_template_title'],
          'bricks_template_ttype' => $shared['bricks_template_ttype'] ?? '',
          'bricks_shortcode'      => $shared['bricks_shortcode'] ?? '',
          'locked'                => false,
          'preview_schema'        => null,
          'match_score'           => null,
          'match_tags'            => $shared['match_tags'] ?? '',
        ];
        continue;
      }

      $bricks_tpl = $this->bricks->pick_bricks_template($t, $bricks_by_type, $used_bricks_ids);

      if ($bricks_tpl) {
        $used_bricks_ids[] = (int) $bricks_tpl['id'];
        $model['sections'][] = [
          'uuid'                  => wp_generate_uuid4(),
          'type'                  => $t,
          'layout_key'            => 'bricks_' . $bricks_tpl['id'],
          'bricks_template_id'    => $bricks_tpl['id'],
          'bricks_template_title' => $bricks_tpl['title'],
          'bricks_template_ttype' => $bricks_tpl['ttype'],
          'bricks_shortcode'      => $bricks_tpl['shortcode'],
          'locked'                => false,
          'preview_schema'        => null,
          'match_score'           => null,
          'match_tags'            => implode(', ', $bricks_tpl['tags']),
        ];
        continue;
      }

      $min_complexity = null;
      if ($pattern === 'homepage' && $t === 'hero') $min_complexity = 40;

      $context = $this->build_selection_context($project_id, $sitemap_version_id, $page_slug, $t);
      $tpl = $this->tpl_lib->pick_best_match($t, $context, $used_layout_keys, $min_complexity);

      $layout_key = $tpl && !empty($tpl['layout_key'])
        ? (string) $tpl['layout_key']
        : ('cf_' . $t . '_section_1');

      if ($tpl && !empty($tpl['layout_key'])) {
        $used_layout_keys[] = (string) $tpl['layout_key'];
      }

      $preview_schema = null;
      if ($tpl && isset($tpl['preview_schema'])) {
        $decoded = json_decode((string) $tpl['preview_schema'], true);
        $preview_schema = is_array($decoded) ? $decoded : null;
      }

      $model['sections'][] = [
        'uuid'                  => wp_generate_uuid4(),
        'type'                  => $t,
        'layout_key'            => $layout_key,
        'bricks_template_id'    => null,
        'bricks_template_title' => null,
        'bricks_template_ttype' => null,
        'bricks_shortcode'      => null,
        'locked'                => false,
        'preview_schema'        => $preview_schema,
        'match_score'           => $tpl && isset($tpl['_match_score']) ? round((float) $tpl['_match_score'], 2) : null,
        'match_tags'            => $tpl && !empty($tpl['tags']) ? (string) $tpl['tags'] : '',
      ];
    }

    $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, true);

    $model = $this->ai->populate_bricks_content_with_ai($model, $project_id, $sitemap_version_id, $page_slug);
    $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, true);
    wp_send_json_success(['wireframe' => $model]);
  }

  public function ajax_save_section_text(): void {
    $this->require_login();
    $this->check_nonce();

    $template_id = isset($_POST['bricks_template_id']) ? (int)$_POST['bricks_template_id'] : 0;
    $changes_raw = isset($_POST['changes']) ? wp_unslash($_POST['changes']) : '';
    $changes     = json_decode($changes_raw, true);

    if (!$template_id || !is_array($changes) || empty($changes)) {
      wp_send_json_error(['message' => 'Missing template_id or changes'], 400);
    }

    $post = get_post($template_id);
    if (!$post || !in_array($post->post_type, ['bricks_template', 'ai_wireframe'], true)) {
      wp_send_json_error(['message' => 'Template not found'], 404);
    }

    $elements = get_post_meta($template_id, '_bricks_page_content_2', true);
    if (!is_array($elements)) {
      wp_send_json_error(['message' => 'No Bricks elements found for this template'], 400);
    }

    $text_keys = ['text', 'title', 'subtitle', 'description', 'heading', 'content', 'label'];
    $changed = 0;

    foreach ($changes as $change) {
      if (!isset($change['original']) || !isset($change['updated'])) continue;
      $original = $change['original'];
      $updated  = $change['updated'];
      if ($original === $updated) continue;

      $original_text = trim(strip_tags($original));

      foreach ($elements as &$node) {
        if (empty($node['settings'])) continue;
        foreach ($text_keys as $key) {
          if (!isset($node['settings'][$key]) || !is_string($node['settings'][$key])) continue;
          $setting_text = trim(strip_tags($node['settings'][$key]));
          if ($setting_text === $original_text) {
            $node['settings'][$key] = wp_kses_post($updated);
            $changed++;
            break 2;
          }
        }
      }
      unset($node);
    }

    if ($changed > 0) {
      update_post_meta($template_id, '_bricks_page_content_2', $elements);
    }

    wp_send_json_success(['changed' => $changed, 'template_id' => $template_id]);
  }

  public function ajax_update_wireframe_page(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $model_raw = isset($_POST['model_json']) ? wp_unslash($_POST['model_json']) : '';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug) wp_send_json_error(['message' => 'Missing params'], 400);
    $model = json_decode($model_raw, true);
    if (!is_array($model)) wp_send_json_error(['message' => 'Invalid model_json'], 400);

    $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, false);
    wp_send_json_success(['ok' => 1]);
  }

  public function ajax_shuffle_section_layout(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $uuid = isset($_POST['uuid']) ? sanitize_text_field(wp_unslash($_POST['uuid'])) : '';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug || !$uuid) wp_send_json_error(['message' => 'Missing params'], 400);

    $row = $this->get_or_create_wireframe_row($project_id, $sitemap_version_id, $page_slug);
    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) $model = [];

    $used_bricks_ids  = [];
    $used_layout_keys = [];
    foreach (($model['sections'] ?? []) as $s) {
      if (!is_array($s)) continue;
      if (!empty($s['bricks_template_id'])) $used_bricks_ids[] = (int) $s['bricks_template_id'];
      if (!empty($s['layout_key'])) $used_layout_keys[] = (string) $s['layout_key'];
    }

    $bricks_by_type = $this->bricks->get_bricks_templates_by_type();

    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s)) continue;
      if (($s['uuid'] ?? '') !== $uuid) continue;
      if (!empty($s['locked'])) wp_send_json_error(['message' => 'Section is locked'], 400);
      $type = (string) ($s['type'] ?? 'generic');

      $bricks_tpl = $this->bricks->pick_bricks_template($type, $bricks_by_type, $used_bricks_ids);
      if ($bricks_tpl) {
        $model['sections'][$i]['layout_key']            = 'bricks_' . $bricks_tpl['id'];
        $model['sections'][$i]['bricks_template_id']    = $bricks_tpl['id'];
        $model['sections'][$i]['bricks_template_title'] = $bricks_tpl['title'];
        $model['sections'][$i]['bricks_template_ttype'] = $bricks_tpl['ttype'];
        $model['sections'][$i]['bricks_shortcode']      = $bricks_tpl['shortcode'];
        $model['sections'][$i]['preview_schema']        = null;
        $model['sections'][$i]['match_tags']            = implode(', ', $bricks_tpl['tags']);
        break;
      }

      $context = $this->build_selection_context($project_id, $sitemap_version_id, $page_slug, $type);
      $tpl = $this->tpl_lib->pick_best_match($type, $context, $used_layout_keys, null);
      $model['sections'][$i]['layout_key']            = $tpl ? (string) $tpl['layout_key'] : ('cf_' . $type . '_1');
      $model['sections'][$i]['bricks_template_id']    = null;
      $model['sections'][$i]['bricks_template_title'] = null;
      $model['sections'][$i]['bricks_template_ttype'] = null;
      $model['sections'][$i]['bricks_shortcode']      = null;
      $model['sections'][$i]['preview_schema']        = $tpl ? json_decode((string) ($tpl['preview_schema'] ?? '{}'), true) : null;
      $model['sections'][$i]['match_score']           = $tpl && isset($tpl['_match_score']) ? round((float) $tpl['_match_score'], 2) : null;
      $model['sections'][$i]['match_tags']            = $tpl && !empty($tpl['tags']) ? (string) $tpl['tags'] : '';
      break;
    }

    $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, true);
    wp_send_json_success(['wireframe' => $model]);
  }

  public function ajax_replace_section_type(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $uuid = isset($_POST['uuid']) ? sanitize_text_field(wp_unslash($_POST['uuid'])) : '';
    $new_type = isset($_POST['new_type']) ? sanitize_key(wp_unslash($_POST['new_type'])) : '';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug || !$uuid || !$new_type) wp_send_json_error(['message' => 'Missing params'], 400);

    $row = $this->get_or_create_wireframe_row($project_id, $sitemap_version_id, $page_slug);
    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) $model = [];

    $used_bricks_ids  = [];
    $used_layout_keys = [];
    foreach (($model['sections'] ?? []) as $s) {
      if (!is_array($s)) continue;
      if (!empty($s['bricks_template_id'])) $used_bricks_ids[] = (int) $s['bricks_template_id'];
      if (!empty($s['layout_key'])) $used_layout_keys[] = (string) $s['layout_key'];
    }

    $bricks_by_type = $this->bricks->get_bricks_templates_by_type();

    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s)) continue;
      if (($s['uuid'] ?? '') !== $uuid) continue;

      $model['sections'][$i]['type'] = $new_type;

      $bricks_tpl = $this->bricks->pick_bricks_template($new_type, $bricks_by_type, $used_bricks_ids);
      if ($bricks_tpl) {
        $model['sections'][$i]['layout_key']            = 'bricks_' . $bricks_tpl['id'];
        $model['sections'][$i]['bricks_template_id']    = $bricks_tpl['id'];
        $model['sections'][$i]['bricks_template_title'] = $bricks_tpl['title'];
        $model['sections'][$i]['bricks_template_ttype'] = $bricks_tpl['ttype'];
        $model['sections'][$i]['bricks_shortcode']      = $bricks_tpl['shortcode'];
        $model['sections'][$i]['preview_schema']        = null;
        $model['sections'][$i]['match_score']           = null;
        $model['sections'][$i]['match_tags']            = implode(', ', $bricks_tpl['tags']);
        break;
      }

      $context = $this->build_selection_context($project_id, $sitemap_version_id, $page_slug, $new_type);
      $tpl = $this->tpl_lib->pick_best_match($new_type, $context, $used_layout_keys, null);
      $model['sections'][$i]['layout_key']            = $tpl ? (string) $tpl['layout_key'] : ('cf_' . $new_type . '_1');
      $model['sections'][$i]['bricks_template_id']    = null;
      $model['sections'][$i]['bricks_template_title'] = null;
      $model['sections'][$i]['bricks_template_ttype'] = null;
      $model['sections'][$i]['bricks_shortcode']      = null;
      $model['sections'][$i]['preview_schema']        = $tpl ? json_decode((string) ($tpl['preview_schema'] ?? '{}'), true) : null;
      $model['sections'][$i]['match_score']           = $tpl && isset($tpl['_match_score']) ? round((float) $tpl['_match_score'], 2) : null;
      $model['sections'][$i]['match_tags']            = $tpl && !empty($tpl['tags']) ? (string) $tpl['tags'] : '';
      break;
    }

    $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, true);
    wp_send_json_success(['wireframe' => $model]);
  }

  public function ajax_compile_wireframe_page(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug) wp_send_json_error(['message' => 'Missing params'], 400);

    $row = $this->get_or_create_wireframe_row($project_id, $sitemap_version_id, $page_slug);
    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) $model = [];
    $compiled = $this->compiler->compile_page($model);
    $compiled_json = wp_json_encode($compiled, JSON_UNESCAPED_SLASHES);

    global $wpdb;
    $table = $this->table_wireframes();
    $wpdb->update($table, [
      'compiled_bricks_json' => $compiled_json,
      'updated_at' => current_time('mysql'),
    ], [
      'project_id' => $project_id,
      'sitemap_version_id' => $sitemap_version_id,
      'page_slug' => $page_slug,
    ], ['%s','%s'], ['%d','%d','%s']);

    wp_send_json_success(['compiled' => $compiled]);
  }

  public function ajax_get_bricks_section_types(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
    $by_type = $this->bricks->get_bricks_templates_by_type();
    $out = [];
    foreach ($by_type as $key => $templates) {
      $out[] = ['type' => $key, 'count' => count($templates)];
    }
    usort($out, fn($a, $b) => strcmp((string) $a['type'], (string) $b['type']));
    wp_send_json_success(['types' => $out]);
  }

  /* ------------------- Helpers ------------------- */

  /**
   * Zoek bestaande header/footer templates uit andere pagina's in hetzelfde project.
   * Zo blijft de navbar en footer consistent over alle pagina's.
   */
  private function find_shared_templates(int $project_id, int $sitemap_version_id, string $exclude_slug): array {
    global $wpdb;
    $table = $this->table_wireframes();
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT model_json FROM {$table} WHERE project_id=%d AND sitemap_version_id=%d AND page_slug!=%s AND model_json IS NOT NULL",
      $project_id, $sitemap_version_id, $exclude_slug
    ), ARRAY_A);

    $shared = [];
    foreach ($rows as $row) {
      $model = json_decode($row['model_json'] ?? '', true);
      if (!is_array($model) || empty($model['sections'])) continue;
      foreach ($model['sections'] as $sec) {
        $type = $sec['type'] ?? '';
        if (!in_array($type, ['header', 'footer'], true)) continue;
        if (empty($sec['bricks_template_id'])) continue;
        if (!isset($shared[$type])) {
          $shared[$type] = $sec;
        }
      }
      if (isset($shared['header']) && isset($shared['footer'])) break;
    }
    return $shared;
  }

  private function save_model(int $project_id, int $sitemap_version_id, string $page_slug, array $model, bool $clear_compiled): void {
    global $wpdb;
    $table = $this->table_wireframes();
    $now = current_time('mysql');
    $data = [
      'model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES),
      'updated_at' => $now,
    ];
    $formats = ['%s','%s'];
    if ($clear_compiled) {
      $data['compiled_bricks_json'] = null;
      $formats[] = '%s';
    }
    $wpdb->update($table, $data, [
      'project_id' => $project_id,
      'sitemap_version_id' => $sitemap_version_id,
      'page_slug' => $page_slug,
    ], $formats, ['%d','%d','%s']);
  }

  public function patterns(): array {
    return [
      'homepage'     => ['header','hero','social_proof','features','testimonials','pricing','faq','cta','footer'],
      'service_page' => ['header','hero','features','process','testimonials','faq','cta','footer'],
      'about'        => ['header','hero','story','team','values','testimonials','cta','footer'],
      'contact'      => ['header','hero','contact_form','locations','faq','cta','footer'],
      'generic'      => ['header','hero','features','faq','cta','footer'],
    ];
  }

  /**
   * Auto-detect the best pattern for a page based on its slug.
   */
  private function detect_pattern(string $slug): string {
    $slug = strtolower($slug);
    $map = [
      'homepage'     => ['home', 'homepage', 'index', 'front', 'frontpage', 'start'],
      'service_page' => ['service', 'services', 'diensten', 'oplossingen', 'solutions', 'product', 'products', 'aanbod'],
      'about'        => ['about', 'about-us', 'over', 'over-ons', 'team', 'ons-verhaal', 'our-story', 'who-we-are'],
      'contact'      => ['contact', 'contact-us', 'get-in-touch', 'neem-contact-op', 'bereik-ons'],
    ];
    foreach ($map as $pattern => $keywords) {
      foreach ($keywords as $kw) {
        if ($slug === $kw || str_contains($slug, $kw)) {
          return $pattern;
        }
      }
    }
    return 'generic';
  }

  private function current_page_has_shortcode(string $shortcode): bool {
    if (!is_singular()) return false;
    global $post;
    if (!$post || empty($post->post_content)) return false;
    return has_shortcode($post->post_content, $shortcode);
  }

  private function build_selection_context(int $project_id, int $sitemap_version_id, string $page_slug, string $section_type): array {
    $brief = (string) get_post_meta($project_id, 'aisb_project_brief', true);
    $page_title = $this->get_page_title_from_sitemap($sitemap_version_id, $page_slug);
    return [
      'brief' => $brief,
      'page_title' => $page_title,
      'page_slug' => $page_slug,
      'section_type' => $section_type,
    ];
  }

  private function get_page_title_from_sitemap(int $sitemap_id, string $page_slug): string {
    if (!$sitemap_id || $page_slug === '') return '';
    $json = get_post_meta($sitemap_id, 'aisb_sitemap_json', true);
    if (!$json) return '';
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return '';

    $needle = $this->normalize_slug($page_slug);
    $candidates = [];

    if (isset($data['sitemap']) && is_array($data['sitemap'])) {
      foreach ($data['sitemap'] as $p) {
        if (!is_array($p)) continue;
        $slug = $this->normalize_slug((string)($p['slug'] ?? $p['page_slug'] ?? $p['url'] ?? $p['path'] ?? ''));
        $title = (string)($p['page_title'] ?? $p['nav_label'] ?? $p['title'] ?? $p['name'] ?? $p['label'] ?? '');
        if ($slug) $candidates[] = ['slug' => $slug, 'title' => $title];
      }
    }

    if (isset($data['pages']) && is_array($data['pages'])) {
      foreach ($data['pages'] as $p) {
        if (!is_array($p)) continue;
        $slug = $this->normalize_slug((string)($p['slug'] ?? $p['page_slug'] ?? $p['url'] ?? $p['path'] ?? ''));
        $title = (string)($p['title'] ?? $p['name'] ?? $p['label'] ?? '');
        if ($slug) $candidates[] = ['slug' => $slug, 'title' => $title];
      }
    }

    $hier = $data['hierarchy'] ?? $data['tree'] ?? $data['structure'] ?? $data['navigation'] ?? null;
    if ($hier) {
      $this->flatten_sitemap_hierarchy($hier, $candidates);
    }

    foreach ($candidates as $c) {
      if (!is_array($c)) continue;
      if (($c['slug'] ?? '') === $needle) {
        return (string)($c['title'] ?? '');
      }
    }
    return '';
  }

  private function flatten_sitemap_hierarchy($nodes, array &$out): void {
    if (!is_array($nodes)) return;
    foreach ($nodes as $n) {
      if (!is_array($n)) continue;
      $slug = $this->normalize_slug((string)($n['slug'] ?? $n['page_slug'] ?? $n['url'] ?? $n['path'] ?? ''));
      $title = (string)($n['title'] ?? $n['name'] ?? $n['label'] ?? '');
      if ($slug) $out[] = ['slug' => $slug, 'title' => $title];
      $kids = $n['children'] ?? $n['items'] ?? $n['pages'] ?? $n['subpages'] ?? null;
      if ($kids) $this->flatten_sitemap_hierarchy($kids, $out);
    }
  }

  private function normalize_slug(string $slug): string {
    $slug = trim($slug);
    $slug = preg_replace('/^\/+/', '', $slug);
    return (string)$slug;
  }
}
