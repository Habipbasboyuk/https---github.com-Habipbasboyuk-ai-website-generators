    // Shortcode + assets
    add_action('init', [$this, 'register_shortcode']);
    add_action('template_redirect', [$this, 'render_bricks_preview']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    // AJAX
    add_action('wp_ajax_aisb_shuffle_section_layout', [$this, 'ajax_shuffle_section_layout']);
    add_action('wp_ajax_aisb_replace_section_type', [$this, 'ajax_replace_section_type']);
    add_action('wp_ajax_aisb_compile_wireframe_page', [$this, 'ajax_compile_wireframe_page']);
    add_action('wp_ajax_aisb_get_bricks_section_types', [$this, 'ajax_get_bricks_section_types']);
    add_action('wp_ajax_aisb_generate_ai_bricks_content', [$this, 'ajax_generate_ai_bricks_content']);
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

    // Load Bricks assets effectively for shortcodes in generic contexts       
    if (function_exists('bricks_enqueue_scripts')) {
      bricks_enqueue_scripts();
    }
    if (class_exists('\Bricks\Assets')) {
      \Bricks\Assets::generate_inline_css();
    }

    show_admin_bar(false);
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
      <meta charset="<?php bloginfo('charset'); ?>">
      <meta name="viewport" content="width=device-width, initial-scale=1">     
      <?php wp_head(); ?>
      <style>
        html, body {
          margin: 0 !important;
          padding: 0 !important;
          background: transparent !important;
          overflow: hidden !important; /* Force hide scrollbars */
        }
        .aisb-bricks-preview-wrap {
          pointer-events: none; /* Disable interaction */
        }
      </style>
    </head>
    <body <?php body_class(); ?>>
      <div class="aisb-bricks-preview-wrap" id="aisb-preview">
        <?php echo do_shortcode('[bricks_template id="' . $id . '"]'); ?>      
      </div>
      <?php wp_footer(); ?>
      <script>
      (function() {
        function reportHeight() {
          var wrap = document.getElementById('aisb-preview');
          var h = wrap ? wrap.getBoundingClientRect().height : document.body.scrollHeight;
          window.parent.postMessage({ type: 'aisb_iframe_height', height: h }, '*');
        }
        window.addEventListener('load', reportHeight);
        if (window.ResizeObserver) {
          new ResizeObserver(reportHeight).observe(document.body);
        }
        setTimeout(reportHeight, 500);
        setTimeout(reportHeight, 2000);
      })();
      </script>
    </body>
    </html>
    <?php
    exit;
  }

  public function enqueue_assets(): void {
    // IMPORTANT: Do not rely only on has_shortcode(). Many sites (incl. Bricks) render shortcodes
    // via templates/elements, where the raw post_content does not contain the shortcode string.
    wp_enqueue_script('aisb-wireframes');
    wp_localize_script('aisb-wireframes', 'AISB_WF', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'previewUrl' => home_url('/?aisb_bricks_preview='),
      // nonce: used for wireframes endpoints.
      'nonce'   => wp_create_nonce('aisb_wf_nonce'),
      // coreNonce: used when calling existing AISB AJAX endpoints that validate against AISB_Plugin::NONCE_ACTION.
      'coreNonce' => wp_create_nonce('aisb_nonce_action'),
      'patterns'     => $this->patterns(),
      'sectionTypes' => $this->get_all_section_types(),
    ]);
    wp_add_inline_script('aisb-wireframes', $this->js(), 'after');
  }
          </div>
        </div>
        <?php if (!$project_id || !$sitemap_id) : ?>
          <div style="background: #fafafa; border: 1px solid #e6e6e6; border-radius: 12px; padding: 24px; text-align: center; margin-top: 14px;">
            <p class="aisb-wf-muted" style="margin-top:0; margin-bottom: 24px; font-size: 15px;">Please select one of your projects below to start generating wireframes.</p>
            <div style="text-align: left; max-width: 800px; margin: 0 auto;">  
              <?php echo do_shortcode('[my-projects title=""]'); ?>
            </div>
          </div>
        <?php else : ?>
        <div class="aisb-wf-layout">
          <div class="aisb-wf-pages">
            <div class="aisb-wf-pages-head">
              <div class="aisb-wf-actions">
                <select data-aisb-wf-pattern class="aisb-select" style="min-width:220px;"></select>
                <button class="aisb-btn-secondary" type="button" data-aisb-wf-generate>Generate wireframe</button>
                <button class="aisb-btn" style="background:#0b6b2f;" type="button" data-aisb-wf-generate-all>Generate all</button>
                <button class="aisb-btn-secondary" type="button" data-aisb-wf-shuffle-page>Shuffle unlocked</button>
                <button class="aisb-btn" type="button" data-aisb-wf-save>Save</button>
                <button class="aisb-btn-secondary" type="button" data-aisb-wf-compile>Compile JSON</button>
            </details>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
      'sections' => [],
    ];
    $bricks_by_type   = $this->get_bricks_templates_by_type();
    $used_bricks_ids  = [];
    $used_layout_keys = [];

    foreach ($types as $t) {
      // --- Primary: pick from Bricks template library ---
      $bricks_tpl = $this->pick_bricks_template($t, $bricks_by_type, $used_bricks_ids);

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

      // --- Fallback: custom template library (legacy) ---
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
    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) $model = [];
    $used_bricks_ids  = [];
    $used_layout_keys = [];
    foreach (($model['sections'] ?? []) as $s) {
      if (!is_array($s)) continue;
      if (!empty($s['bricks_template_id'])) $used_bricks_ids[] = (int) $s['bricks_template_id'];
      if (!empty($s['layout_key'])) $used_layout_keys[] = (string) $s['layout_key'];
    }
    $bricks_by_type = $this->get_bricks_templates_by_type();

    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s)) continue;
      if (($s['uuid'] ?? '') !== $uuid) continue;
      if (!empty($s['locked'])) wp_send_json_error(['message' => 'Section is locked'], 400);
      $type = (string) ($s['type'] ?? 'generic');

      // Primary: shuffle within Bricks templates
      $bricks_tpl = $this->pick_bricks_template($type, $bricks_by_type, $used_bricks_ids);
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

      // Fallback: custom library
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
    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) $model = [];
    $used_bricks_ids  = [];
    $used_layout_keys = [];
    foreach (($model['sections'] ?? []) as $s) {
      if (!is_array($s)) continue;
      if (!empty($s['bricks_template_id'])) $used_bricks_ids[] = (int) $s['bricks_template_id'];
      if (!empty($s['layout_key'])) $used_layout_keys[] = (string) $s['layout_key'];
    }
    $bricks_by_type = $this->get_bricks_templates_by_type();

    foreach (($model['sections'] ?? []) as $i => $s) {
      if (!is_array($s)) continue;
      if (($s['uuid'] ?? '') !== $uuid) continue;

      $model['sections'][$i]['type'] = $new_type;

      // Primary: Bricks templates
      $bricks_tpl = $this->pick_bricks_template($new_type, $bricks_by_type, $used_bricks_ids);
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

      // Fallback: custom library
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
    wp_send_json_success(['compiled' => $compiled]);
  }
  public function ajax_generate_ai_bricks_content(): void {
    $this->require_login();
    $this->check_nonce();
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0; 
    $sitemap_version_id = isset($_POST['sitemap_version_id']) ? (int)$_POST['sitemap_version_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $uuid = isset($_POST['uuid']) ? sanitize_text_field(wp_unslash($_POST['uuid'])) : '';
    
    $this->assert_project_ownership($project_id);
    if (!$sitemap_version_id || !$page_slug || !$uuid) {
      wp_send_json_error(['message' => 'Missing params'], 400);
    }

    $row = $this->get_or_create_wireframe_row($project_id, $sitemap_version_id, $page_slug);
    $model = json_decode((string)($row['model_json'] ?? '{}'), true);
    if (!is_array($model)) $model = [];

    // Trigger compiler magic content generation logic here
    try {
      $model = $this->compiler->generate_ai_bricks_content_for_section($model, $uuid, $project_id, $sitemap_version_id, $page_slug);
      $this->save_model($project_id, $sitemap_version_id, $page_slug, $model, true);
      wp_send_json_success(['wireframe' => $model]);
    } catch (\Exception $e) {
      wp_send_json_error(['message' => $e->getMessage()]);
    }
  }

  /* ------------------- Bricks template source ------------------- */

  /**
   * Query all published Bricks templates and group them by WordPress tag slug.
   * Falls back to `_bricks_template_type` meta when a template has no tags.   
   * Result is statically cached per request.
   *
   * @return array<string, list<array{id:int,title:string,ttype:string,tags:string[],shortcode:string}>>
   */
  private function get_bricks_templates_by_type(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    if (!post_type_exists('bricks_template')) {
      $cache = [];
      return $cache;
    }

    $posts = get_posts([
      'post_type'      => 'bricks_template',
      'post_status'    => 'publish',
      'posts_per_page' => 500,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'no_found_rows'  => true,
    ]);

    $by_type = [];

    foreach ($posts as $post) {
      $id    = (int) $post->ID;
      $title = (string) $post->post_title;
      $ttype = (string) (get_post_meta($id, '_bricks_template_type', true) ?: '');
        $tags  = wp_get_object_terms($id, 'template_tag', ['fields' => 'slugs']);
        if (is_wp_error($tags)) $tags = [];
      $type_keys = array_map('strtolower', (array) $tags);

      // Fallback: use Bricks template type when no tags are present.
      if (empty($type_keys) && $ttype !== '') {
        $type_keys = [strtolower($ttype)];
      }

      $entry = [
        'id'        => $id,
        'title'     => $title,
        'ttype'     => $ttype,
        'tags'      => $type_keys,
        'shortcode' => '[bricks_template id="' . $id . '"]',
      ];

      foreach ($type_keys as $key) {
        if (!isset($by_type[$key])) $by_type[$key] = [];
        $by_type[$key][] = $entry;
      }
    }

    $cache = $by_type;
    return $cache;
  }

  /**
   * Pick a random Bricks template for $section_type, respecting exclusions.   
   * Falls back to configured aliases when no direct match exists.
   *
   * @param array<string,list<array>> $by_type     Output of get_bricks_templates_by_type()
   * @param int[]                     $exclude_ids Template IDs already used on this page
   */
  private function pick_bricks_template(string $section_type, array $by_type, array $exclude_ids = []): ?array {
    $try = function(string $key) use ($by_type, $exclude_ids): ?array {        
      $pool = $by_type[strtolower($key)] ?? [];
      if ($exclude_ids) {
        $pool = array_values(array_filter($pool, function($t) use ($exclude_ids) {
          return !in_array((int) $t['id'], $exclude_ids, true);
        }));
      }
      return $pool ? $pool[array_rand($pool)] : null;
    };

    $tpl = $try($section_type);
    if ($tpl) return $tpl;

    foreach ($this->section_type_aliases()[$section_type] ?? [] as $alt) {     
      $tpl = $try($alt);
      if ($tpl) return $tpl;
    }

      // 1st Fallback: Probeer algemene templates te gebruiken zoals 'content', 'features', of 'generic'
      foreach (['content', 'features', 'generic'] as $alt) {
        $tpl = $try($alt);
        if ($tpl) return $tpl;
      }

      // 2nd Fallback: Pak een willekeurige Bricks template uit je bibliotheek (uitgezonderd header/footer)
      $all_pool = [];
      foreach ($by_type as $k => $templates) {
        if (in_array(strtolower($k), ['header', 'footer'])) continue;
        foreach ($templates as $t) {
          if (!in_array((int) $t['id'], $exclude_ids, true)) {
            $all_pool[$t['id']] = $t;
          }
        }
      }
      if ($all_pool) {
        return $all_pool[array_rand($all_pool)];
      }

      return null;
    }

    /** Tag aliases so e.g. "features" also matches "feature", "services" etc. */
    private function section_type_aliases(): array {
      return [
        'features'     => ['feature', 'benefits', 'benefit', 'services', 'service'],
        'testimonials' => ['testimonial', 'reviews', 'review'],
        'cta'          => ['call-to-action', 'call_to_action'],
        'faq'          => ['faqs', 'questions'],
      'pricing'      => ['plans', 'plan', 'packages'],
      'social_proof' => ['logos', 'clients', 'brands', 'partners', 'trust'],   
      'team'         => ['staff', 'people'],
      'contact_form' => ['contact', 'form', 'contact-form'],
      'header'       => ['nav', 'navigation', 'navbar', 'menu'],
    ];
  }

  /**
   * All unique section-type keys: built-ins merged with available Bricks tags.
   * @return string[]
   */
  private function get_all_section_types(): array {
    $builtin = ['header','hero','features','process','testimonials','pricing','faq','cta','content','team','story','values','contact_form','locations','social_proof','footer','generic'];
    $bricks  = array_keys($this->get_bricks_templates_by_type());
    $merged  = array_unique(array_merge($builtin, $bricks));
    sort($merged);
    return array_values($merged);
  }

  /** AJAX: return all available Bricks section types (tag → count). */        
  public function ajax_get_bricks_section_types(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
    $by_type = $this->get_bricks_templates_by_type();
    $out = [];
    foreach ($by_type as $key => $templates) {
      $out[] = ['type' => $key, 'count' => count($templates)];
    }
    usort($out, fn($a, $b) => strcmp((string) $a['type'], (string) $b['type']));
    wp_send_json_success(['types' => $out]);
  }

  private function save_model(int $project_id, int $sitemap_version_id, string $page_slug, array $model, bool $clear_compiled): void {
    global $wpdb;
    $table = $this->table_wireframes();
  public function patterns(): array {
    return [
      'homepage'     => ['hero','social_proof','features','testimonials','pricing','faq','cta'],
      'service_page' => ['hero','features','process','testimonials','faq','cta'],
      'about'        => ['hero','story','team','values','testimonials','cta'], 
      'contact'      => ['hero','contact_form','locations','faq','cta'],       
      'generic'      => ['hero','features','faq','cta'],
    ];
  }
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

  /* ------------------- UI assets ------------------- */
  private function css(): string {
.aisb-wf-canvas-title{font-weight:800; font-size:14px;}
.aisb-wf-actions{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}   
.aisb-wf-status{padding:10px 12px; font-size:13px;}
.aisb-wf-sections{padding:0; display:flex; flex-direction:column; gap:0;}      
.aisb-wf-section{position:relative; width:100%; border-bottom:1px dashed rgba(0,0,0,.15); overflow:hidden;}
.aisb-wf-section-head{position:absolute; top:0; left:0; right:0; z-index:10; display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 12px; background:rgba(255,255,255,.94); box-shadow:0 1px 3px rgba(0,0,0,.05); opacity:0; transition:opacity .2s; pointer-events:none;}
  .aisb-wf-section:hover .aisb-wf-section-head{opacity:1; pointer-events:auto;}
.aisb-wf-section-head strong{font-size:13px;}
.aisb-wf-controls{display:flex; gap:6px; align-items:center; flex-wrap:wrap;}  
.aisb-wf-iconbtn{border:1px solid rgba(0,0,0,.14); background:#fff; border-radius:10px; padding:6px 8px; cursor:pointer; font-size:12px;}
.aisb-wf-iconbtn:hover{background:#f6f6f6;}
.aisb-wf-lock{opacity:.75;}
.aisb-wf-body{padding:0; background:#fff;}
.aisb-wf-skel{border:1px dashed rgba(0,0,0,.18); border-radius:12px; padding:12px; background:linear-gradient(0deg, rgba(0,0,0,.02), rgba(0,0,0,.02));}        
.aisb-wf-hero-grid{display:grid; grid-template-columns:1.15fr .85fr; gap:16px; align-items:center;}
@media (max-width: 860px){.aisb-wf-hero-grid{grid-template-columns:1fr;}}      
.aisb-wf-cards{display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:10px; margin-top:12px;}
.aisb-wf-card{height:70px; border-radius:12px; background:rgba(0,0,0,.10);}    
.aisb-wf-raw{margin:12px;}
/* Bricks template card preview */
.aisb-wf-bricks-preview{display:flex; align-items:center; gap:14px; padding:16px 12px; border-radius:12px; background:linear-gradient(135deg,#f0f4ff 0%,#f8f8ff 100%); border:1px solid rgba(90,110,255,.14);}
.aisb-wf-bricks-icon{width:44px; height:44px; border-radius:12px; background:#111; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:18px; flex-shrink:0; letter-spacing:-.04em;}
.aisb-wf-bricks-info{display:flex; flex-direction:column; gap:5px; min-width:0;}
.aisb-wf-bricks-title{font-weight:700; font-size:14px; color:rgba(0,0,0,.82); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
.aisb-wf-bricks-sc{font-size:11px; font-family:monospace; background:rgba(0,0,0,.06); border-radius:6px; padding:3px 7px; display:inline-block; color:rgba(0,0,0,.55); white-space:nowrap;}
.aisb-wf-bricks-type{font-size:11px; color:rgba(0,0,0,.45); font-style:italic;}
.aisb-wf-bricks-notfound{padding:16px 12px; background:rgba(0,0,0,.03); border-radius:12px; border:1px dashed rgba(0,0,0,.15); font-size:12px; color:rgba(0,0,0,.45);}
CSS;
  }
  const elPattern = root.querySelector('[data-aisb-wf-pattern]');
  const btnGenerate = root.querySelector('[data-aisb-wf-generate]');
  const btnGenerateAll = root.querySelector('[data-aisb-wf-generate-all]');    
  const btnSave = root.querySelector('[data-aisb-wf-save]');
  const btnShufflePage = root.querySelector('[data-aisb-wf-shuffle-page]');    
  const btnCompile = root.querySelector('[data-aisb-wf-compile]');
  const patterns     = (window.AISB_WF && AISB_WF.patterns) ? AISB_WF.patterns : {};
  const sectionTypes = (window.AISB_WF && AISB_WF.sectionTypes && AISB_WF.sectionTypes.length) ? AISB_WF.sectionTypes : ['hero','features','process','testimonials','pricing','faq','cta','content','team','story','values','contact_form','locations','social_proof','footer','header','generic'];
  const patternKeys = Object.keys(patterns);
  elPattern.innerHTML = patternKeys.map(k => `<option value="${k}">${k.replace(/_/g,' ')}</option>`).join('');
    return out;
  }
  function bricksTemplatePreview(section){
    const id    = section.bricks_template_id;
    if (!id) return '<div class="aisb-wf-bricks-notfound">No Bricks template assigned.</div>';
    const title = section.bricks_template_title || ('Template #' + id);        
    const sc    = section.bricks_shortcode || ('[bricks_template id="' + id + '"]');
    const ttype = section.bricks_template_ttype ? `<div class="aisb-wf-bricks-type" style="display:inline-block; margin-right:8px;">${escapeHtml(section.bricks_template_ttype)}</div>` : '';
    const iframeUrl = (AISB_WF.previewUrl || '') + id;

    return `
    <div style="background:#fff; border:none; border-radius:0; overflow:hidden; position:relative; display:flex; flex-direction:column;">
      <div style="width:100%; position:relative;">
        <button type="button" class="aisb-btn" data-act="magic" style="position:absolute; top:10px; right:10px; z-index:99; background:linear-gradient(135deg, #7c3aed, #4f46e5); border:none; color:#fff; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; cursor:pointer; box-shadow:0 4px 10px rgba(79,70,229,0.3);">+ AI Magic</button>
        <iframe src="${escapeHtml(iframeUrl)}" loading="lazy" class="aisb-bricks-iframe" style="width:100%; height:400px; border:none; display:block;" title="Bricks Preview" scrolling="no"></iframe>
      </div>
    </div>`;
  }

  // Auto-resize iframes
  window.addEventListener('message', function(e) {
    if (e.data && e.data.type === 'aisb_iframe_height' && e.data.height) {     
      const iframes = document.querySelectorAll('iframe.aisb-bricks-iframe');  
      for (let i = 0; i < iframes.length; i++) {
        if (iframes[i].contentWindow === e.source) {
          iframes[i].style.height = e.data.height + 'px';
          break;
        }
      }
    }
  });

  function renderSections(){
    const model = state.model || {};
    const sections = Array.isArray(model.sections) ? model.sections : [];      
      const locked = !!s.locked;
      const schema = schemaFromSection(s);
      const lockTxt = locked ? 'Unlock' : 'Lock';
      const isBricks = !!(s.bricks_template_id);
      const bricksBadge = isBricks
        ? `<span style="display:inline-block;margin-left:8px;padding:1px 7px;border-radius:999px;font-size:11px;background:#111;color:#fff;font-weight:600;vertical-align:middle;">Bricks #${escapeHtml(String(s.bricks_template_id))}</span>` 
        : '';
      const score = (!isBricks && s.match_score !== undefined && s.match_score !== null) ? (' · score ' + (Math.round(parseFloat(s.match_score) * 10) / 10)) : '';
      const tags  = (!isBricks && s.match_tags) ? (' · ' + s.match_tags) : ''; 
      const bodyHtml = isBricks ? bricksTemplatePreview(s) : sectionPreview(schemaFromSection(s));
      return `
      <div class="aisb-wf-section" data-uuid="${escapeHtml(s.uuid)}">
        <div class="aisb-wf-section-head">
          <div>
            <strong>${escapeHtml(type)}</strong>${bricksBadge}
            <span class="aisb-wf-muted" style="margin-left:8px;">${isBricks ? escapeHtml(s.bricks_template_title||'') : (escapeHtml(s.layout_key||'') + escapeHtml(score) + escapeHtml(tags))}</span>
          </div>
          <div class="aisb-wf-controls">
            <button class="aisb-wf-iconbtn" data-act="up" ${idx===0?'disabled':''}>↑</button>
            <button class="aisb-wf-iconbtn" data-act="down" ${idx===sections.length-1?'disabled':''}>↓</button>
            <select class="aisb-wf-iconbtn" data-act="type">
              ${sectionTypes.map(t => `<option value="${t}" ${t===type?'selected':''}>${t}</option>`).join('')}
            </select>
            <button class="aisb-wf-iconbtn" data-act="shuffle" ${locked?'disabled':''}>Shuffle</button>
            <button class="aisb-wf-iconbtn aisb-wf-lock" data-act="lock">${lockTxt}</button>
            <button class="aisb-wf-iconbtn" data-act="del">Delete</button>     
          </div>
        </div>
        <div class="aisb-wf-body">${bodyHtml}</div>
      </div>`;
    }).join('');
  }
      }
      return;
    }
    if (act === 'magic') {
      setStatus('Generating AI content for section...', 'ok');
      const out = await post('aisb_generate_ai_bricks_content', {
        project_id: state.projectId,
        sitemap_version_id: state.sitemapId,
        page_slug: state.pageSlug,
        uuid
      });
      if (out && out.success) {
        state.model = out.data.wireframe;
        renderSections();
        setStatus('AI Magic completed.', 'ok');
      } else {
        setStatus((out && out.data && out.data.message) ? out.data.message : 'AI Magic failed', 'err');
      }
      return;
    }
  });
  elSections.addEventListener('change', async (e)=>{
    }
  });
  btnGenerateAll?.addEventListener('click', async ()=>{
    if (!state.pages || state.pages.length === 0) {
      setStatus('No pages found in sitemap.', 'err');
      return;
    }
    if (!confirm('This will generate wireframes for ALL pages sequentially. Existing un-saved wireframe edits could be overridden. Continue?')) {
      return;
    }

    btnGenerateAll.disabled = true;
    btnGenerate.disabled = true;
    
    const initialSlug = state.pageSlug;
    let successCount = 0;
    let failCount = 0;
    
    for (const p of state.pages) {
      state.pageSlug = p.slug;
      
      // Update UI active state visually
      const allBtns = elPagesList.querySelectorAll('.aisb-wf-page-btn');       
      allBtns.forEach(b => b.classList.remove('is-active'));
      const activeBtn = elPagesList.querySelector(`[data-aisb-wf-page-btn="${p.slug}"]`);
      if (activeBtn) activeBtn.classList.add('is-active');
      
      elPageTitle.textContent = p.title;
      elPageSub.textContent = p.slug;
      elSections.innerHTML = '<div class="aisb-wf-muted" style="padding:16px;">Generating wireframe... Please wait.</div>';
      
      setStatus(`Generating wireframe for ${p.title} (${successCount + failCount + 1}/${state.pages.length})...`, 'ok');

      try {
        const out = await post('aisb_generate_wireframe_page', {
          project_id: state.projectId,
          sitemap_version_id: state.sitemapId,
          page_slug: p.slug,
          pattern: 'generic' // Default to generic for bulk generation to ensure consistency, or could use elPattern.value but generic is safer
        });
        
        if (out && out.success) {
          state.model = out.data.wireframe;
          renderSections();

          // Auto-save the individual page so it persists
          setStatus(`Saving wireframe for ${p.title}...`, 'ok');
          await post('aisb_update_wireframe_page', {
            project_id: state.projectId,
            sitemap_version_id: state.sitemapId,
            page_slug: p.slug,
            model_json: JSON.stringify(state.model)
          });

          successCount++;
        } else {
          failCount++;
          elSections.innerHTML = `<div class="aisb-error" style="padding:16px;">Generation failed for ${p.title}.</div>`;
        }
      } catch (e) {
        failCount++;
        elSections.innerHTML = `<div class="aisb-error" style="padding:16px;">Error mapping ${p.title}.</div>`;
      }
    }
    
    btnGenerateAll.disabled = false;
    btnGenerate.disabled = false;
    
    // Load back initial or first page
    state.pageSlug = initialSlug || state.pages[0].slug;
    loadWireframePage(state.pageSlug);
    
    setStatus(`Finished! Generated: ${successCount}. Failed: ${failCount}.`, failCount > 0 ? 'err' : 'ok');
  });

  btnShufflePage.addEventListener('click', async ()=>{
    // client-side shuffle: call shuffle endpoint per unlocked section
    if (!state.model || !state.model.sections) return;
