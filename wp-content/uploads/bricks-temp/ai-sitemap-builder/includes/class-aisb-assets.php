<?php

if (!defined('ABSPATH')) exit;

class AISB_Assets {

  private static bool $assets_enqueued = false;

  /**
   * Shortcode: [my-projects]
   *
   * Purpose:
   * - Provide an overview of the current user's projects (aisb_project)
   * - Allow selecting a project and a specific sitemap version (aisb_sitemap)
   * - Redirect (via GET params) to a page that contains [ai_sitemap_builder]
   *   so the builder can auto-load the chosen snapshot.
   *
   * Attributes:
   * - builder_url: optional absolute/relative URL to the page that hosts [ai_sitemap_builder].
   *   If omitted, the current page URL is used.
   */
  public function render_my_projects_shortcode($atts = [], $content = null) {
    if (!is_user_logged_in()) {
      return '<div class="aisb-wrap"><div class="aisb-card"><p>You must be logged in to view your projects.</p></div></div>';
    }

    $atts = shortcode_atts([
      'builder_url' => '',
      'wireframes_url' => '',
      'title'       => 'My Projects',
    ], $atts);

    $current_url = '';
    if (function_exists('home_url')) {
      $scheme = is_ssl() ? 'https' : 'http';
      $current_url = home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? ''), $scheme);
    }

    $builder_url = trim((string) $atts['builder_url']);
    if ($builder_url === '') {
      $builder_url = $current_url;
    }

    $wireframes_url = trim((string) $atts['wireframes_url']);
    if ($wireframes_url === '') {
      // Default: assume the same page can host [ai_wireframes] OR use another page via attribute.
      $wireframes_url = $builder_url;
    }

    // Clean builder_url of prior AISB params to avoid stacking.
    $builder_url = remove_query_arg(['aisb_project', 'aisb_sitemap', 'aisb_version', 'aisb_step'], $builder_url);
    $wireframes_url = remove_query_arg(['aisb_project', 'aisb_sitemap', 'aisb_version', 'aisb_step'], $wireframes_url);


    $projects_q = new WP_Query([
      'post_type'      => 'aisb_project',
      'post_status'    => 'publish',
      'posts_per_page' => 100,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'author'         => get_current_user_id(),
      'fields'         => 'ids',
    ]);

    $project_ids = $projects_q->posts;

    // Fetch all sitemap versions for these projects in one query (faster than N+1).
    $versions_by_project = [];
    if (!empty($project_ids)) {
      $sitemaps_q = new WP_Query([
        'post_type'      => 'aisb_sitemap',
        'post_status'    => 'publish',
        'posts_per_page' => 500,
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'meta_key'       => 'aisb_sitemap_version',
        'meta_query'     => [
          [
            'key'     => 'aisb_project_id',
            'value'   => array_map('intval', $project_ids),
            'compare' => 'IN',
          ],
        ],
        'fields' => 'ids',
      ]);

      foreach ($sitemaps_q->posts as $sid) {
        $pid = (int) get_post_meta($sid, 'aisb_project_id', true);
        if (!$pid) continue;

        if (!isset($versions_by_project[$pid])) {
          $versions_by_project[$pid] = [];
        }

        $versions_by_project[$pid][] = [
          'id'        => (int) $sid,
          'version'   => (int) get_post_meta($sid, 'aisb_sitemap_version', true),
          'label'     => (string) get_post_meta($sid, 'aisb_sitemap_label', true),
          'status'    => (string) get_post_meta($sid, 'aisb_sitemap_status', true),
          'current'   => (int) get_post_meta($sid, 'aisb_sitemap_is_current', true) === 1,
          'createdAt' => get_post_time('U', true, $sid),
        ];
      }

      // Ensure newest-first sort per project (in case WP_query mixes ordering across projects).
      foreach ($versions_by_project as $pid => $list) {
        usort($list, function($a, $b) {
          // Prefer explicit version, fallback to created time.
          if ((int)$a['version'] !== (int)$b['version']) {
            return ((int)$b['version'] <=> (int)$a['version']);
          }
          return ((int)$b['createdAt'] <=> (int)$a['createdAt']);
        });
        $versions_by_project[$pid] = $list;
      }
    }

    // Render cards overview.
    ob_start();
    ?>
    <div class="aisb-wrap">
      <div class="aisb-card">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
          <h3 style="margin:0;"><?php echo esc_html($atts['title']); ?></h3>
          <div style="font-size:13px; color:#555;">
            Tip: put <code>[my-projects]</code> above <code>[ai_sitemap_builder]</code> so clicking a version instantly loads it.
          </div>
        </div>

        <?php if (empty($project_ids)) : ?>
          <p style="margin-top:10px;">You don't have any projects yet. Generate a sitemap first to create your first project.</p>
        <?php else : ?>
          <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:14px; margin-top:14px;">
            <?php foreach ($project_ids as $pid) : ?>
              <?php
                $title = get_the_title($pid);
                $brief = (string) get_post_meta($pid, 'aisb_project_brief', true);
                $versions = $versions_by_project[$pid] ?? [];
                $latest = $versions[0]['id'] ?? 0;
              ?>
              <div style="border:1px solid #e6e6e6; border-radius:12px; padding:14px; background:#fff;">
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px;">
                  <div>
                    <div style="font-weight:700; font-size:15px; line-height:1.2;"><?php echo esc_html($title ?: ('Project #' . (int)$pid)); ?></div>
                    <?php if (!empty($brief)) : ?>
                      <div style="margin-top:6px; font-size:13px; color:#555; white-space:pre-wrap;"><?php echo esc_html($brief); ?></div>
                    <?php endif; ?>
                  </div>
                  <?php if ($latest) : ?>
                    <?php
                      $latest_url = add_query_arg([
                        'aisb_project' => (int) $pid,
                        'aisb_sitemap' => (int) $latest,
                      ], $builder_url);

                      $latest_wf_url = add_query_arg([
                        'aisb_project' => (int) $pid,
                        'aisb_sitemap' => (int) $latest,
                        'aisb_step'    => 2,
                      ], $wireframes_url);
                    ?>
                    <div style="display:flex; gap:8px; align-items:center;">
                      <a class="aisb-btn-secondary" style="text-decoration:none;" href="<?php echo esc_url($latest_url); ?>">Open sitemap</a>
                      <a class="aisb-btn" style="text-decoration:none;" href="<?php echo esc_url($latest_wf_url); ?>">Wireframes</a>
                    </div>
                  <?php endif; ?>
                </div>

                <div style="margin-top:12px;">
                  <div style="font-size:12px; font-weight:600; color:#666; margin-bottom:6px;">Versions</div>
                  <?php if (empty($versions)) : ?>
                    <div style="font-size:13px; color:#777;">No versions yet.</div>
                  <?php else : ?>
                    <div style="display:flex; flex-wrap:wrap; gap:8px;">
                      <?php foreach ($versions as $v) : ?>
                        <?php
                          $v_label = 'v' . (int) $v['version'];
                          if (!empty($v['label'])) $v_label .= ' · ' . $v['label'];
                          if (!empty($v['current'])) $v_label .= ' · current';
                          $v_url = add_query_arg([
                            'aisb_project' => (int) $pid,
                            'aisb_sitemap' => (int) $v['id'],
                          ], $builder_url);

                          $v_wf_url = add_query_arg([
                            'aisb_project' => (int) $pid,
                            'aisb_sitemap' => (int) $v['id'],
                            'aisb_step'    => 2,
                          ], $wireframes_url);
                        ?>
                        <span style="display:inline-flex; align-items:center; gap:6px;">
                          <a href="<?php echo esc_url($v_url); ?>" style="display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border:1px solid #e6e6e6; border-radius:999px; font-size:12px; text-decoration:none; color:#111;">
                            <?php echo esc_html($v_label); ?>
                          </a>
                          <a href="<?php echo esc_url($v_wf_url); ?>" title="Wireframes" style="display:inline-flex; align-items:center; padding:6px 10px; border:1px solid #e6e6e6; border-radius:999px; font-size:12px; text-decoration:none; color:#111; background:#fafafa;">WF</a>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php

    return ob_get_clean();
  }

      public function render_shortcode($atts = [], $content = null) {
    $atts = shortcode_atts([
      'title' => 'Sitemap Builder',
      'placeholder' => "Describe the website you want to build.\nExample: “A modern website for a hearing clinic in Belgium. Services: hearing tests, hearing aids, tinnitus coaching. Needs online appointment booking and testimonials.”",
      'button' => 'Generate sitemap',
    ], $atts);

    $this->enqueue_assets_for_shortcode();

    $step = isset($_GET['aisb_step']) ? (int) $_GET['aisb_step'] : 1;
    if ($step < 1 || $step > 4) $step = 1;

    $project_id = isset($_GET['aisb_project']) ? (int) $_GET['aisb_project'] : 0;
    $sitemap_id = isset($_GET['aisb_sitemap']) ? (int) $_GET['aisb_sitemap'] : 0;

    // Build tab URLs (preserve project + sitemap).
    $base_url = remove_query_arg(['aisb_step'], (function_exists('home_url') ? home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? ''), is_ssl() ? 'https' : 'http') : ''));
    if (!$base_url) {
      $base_url = remove_query_arg(['aisb_step'], add_query_arg([], $_SERVER['REQUEST_URI'] ?? ''));
    }
    $tab1_url = remove_query_arg(['aisb_step'], $base_url);
    $tab2_url = add_query_arg(['aisb_step' => 2], $base_url);

    ob_start(); ?>
      <div class="aisb-wrap" data-aisb data-aisb-step="<?php echo esc_attr($step); ?>">
        <div class="aisb-header">
          <h2 class="aisb-title"><?php echo esc_html($atts['title']); ?></h2>
          <p class="aisb-subtitle">
            Type a brief. You’ll get a complete sitemap with <strong>hierarchy</strong> + sections.
          </p>
        </div>

        <div class="aisb-steps">
          <a class="aisb-step-tab <?php echo $step === 1 ? 'is-active' : ''; ?>" href="<?php echo esc_url($tab1_url); ?>">Step 1 · Sitemap</a>
          <a class="aisb-step-tab <?php echo $step === 2 ? 'is-active' : ''; ?>" href="<?php echo esc_url($tab2_url); ?>">Step 2 · Wireframes</a>
        </div>

        <div class="aisb-step-panel" data-aisb-step-panel="1" style="<?php echo $step === 1 ? '' : 'display:none;'; ?>">
        <div class="aisb-card aisb-input-card">
          <label class="aisb-label" for="aisb-prompt">Website brief</label>
          <textarea id="aisb-prompt" class="aisb-textarea" rows="7" maxlength="4000"
            placeholder="<?php echo esc_attr($atts['placeholder']); ?>"></textarea>
          <div class="aisb-brief-grid">
              <div class="aisb-field">
                <label class="aisb-label" for="aisb-languages">Languages</label>
                <select id="aisb-languages" class="aisb-select">
                  <option value="English">English</option>
                  <option value="French">French</option>
                  <option value="Dutch">Dutch</option>
                  <option value="German">German</option>
                </select>
              </div>
            
              <div class="aisb-field">
                <label class="aisb-label" for="aisb-pagecount">Number of pages</label>
                <select id="aisb-pagecount" class="aisb-select">
                  <option value="1">1</option>
                  <option value="2-5">2-5</option>
                  <option value="5-10" selected>5-10</option>
                  <option value="10-15">10-15</option>
                  <option value="15+">15+</option>
                </select>
              </div>
            </div>

          <div class="aisb-row">
            <button class="aisb-btn" type="button" data-aisb-generate>
              <?php echo esc_html($atts['button']); ?>
            </button>
            <div class="aisb-hint">
              <span data-aisb-demo-note class="aisb-demo-note" style="display:none;">
                Demo mode (set API key in Settings → AI Sitemap Builder to enable live generation)
              </span>
              <span data-aisb-counter class="aisb-counter">0 / 4000</span>
            </div>
          </div>

          <div class="aisb-status" data-aisb-status aria-live="polite"></div>
        </div>

        <div class="aisb-card aisb-output-card" data-aisb-output style="display:none;">
          <div class="aisb-output-head">
            <h3 class="aisb-output-title">Generated sitemap</h3>
            <div class="aisb-actions">
              <button class="aisb-btn-secondary" type="button" data-aisb-add-page>
                <span class="aisb-plus">+</span> Add page
              </button>
              <button class="aisb-btn-secondary" type="button" data-aisb-fit>Fit</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-zoomout>−</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-zoomin>+</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-copy>Copy JSON</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-save>Save version</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-reset>Reset</button>
            </div>
          </div>

          <div class="aisb-summary" data-aisb-summary></div>

          <div class="aisb-workspace">
            <div class="aisb-detail-panel" data-aisb-detail-panel>
              <div class="aisb-detail-head">
                <div class="aisb-detail-title" data-aisb-detail-title>Select a page</div>
                <div class="aisb-detail-sub" data-aisb-detail-sub>We’ll show sections + SEO for that page.</div>
              </div>
              <div class="aisb-detail-body" data-aisb-detail-body></div>
            </div>

            <div class="aisb-canvas-wrap">
              <div class="aisb-canvas" data-aisb-canvas>
                <div class="aisb-viewport" data-aisb-viewport>
                  <svg class="aisb-edges" data-aisb-edges aria-hidden="true"></svg>
                  <div class="aisb-nodes" data-aisb-nodes></div>
                </div>
                <div class="aisb-canvas-hint">Drag background to pan · Scroll to zoom · Drag cards to reposition</div>
              </div>
            </div>
          </div>

          <details class="aisb-raw">
            <summary>Raw JSON output</summary>
            <pre class="aisb-pre" data-aisb-raw></pre>
          </details>
        </div>
        </div><!-- /Step 1 panel -->

        <?php if ($step === 2) : ?>
        <div class="aisb-step-panel" data-aisb-step-panel="2">
          <div class="aisb-card aisb-wireframes-card" data-aisb-wireframes
               data-project-id="<?php echo esc_attr($project_id); ?>"
               data-sitemap-id="<?php echo esc_attr($sitemap_id); ?>">
            <div class="aisb-wf-head">
              <div>
                <h3 class="aisb-output-title" style="margin:0;">Wireframes</h3>
                <p class="aisb-subtitle" style="margin-top:6px;">Relume-like preview · Brixies sections · fast skeleton rendering</p>
              </div>
            </div>

            <?php if (!is_user_logged_in()) : ?>
              <p>You must be logged in to use wireframes.</p>
            <?php elseif (!$project_id || !$sitemap_id) : ?>
              <p class="aisb-wf-muted">Open a project + sitemap version first (e.g. via <code>[my-projects]</code>), then click the Wireframes tab.</p>
            <?php else : ?>
              <div class="aisb-wf-layout">
                <div class="aisb-wf-pages">
                  <div class="aisb-wf-pages-head">
                    <strong>Pages</strong>
                    <span class="aisb-wf-muted" data-aisb-wf-pages-meta></span>
                  </div>
                  <div class="aisb-wf-pages-list" data-aisb-wf-pages></div>
                </div>

                <div class="aisb-wf-canvas">
                  <div class="aisb-wf-canvas-head">
                    <div>
                      <div class="aisb-wf-canvas-title" data-aisb-wf-page-title>Select a page</div>
                      <div class="aisb-wf-muted" data-aisb-wf-page-sub>Generate a wireframe to start editing.</div>
                    </div>
                    <div class="aisb-wf-actions">
                      <select data-aisb-wf-pattern class="aisb-select" style="min-width:220px;"></select>
                      <button class="aisb-btn-secondary" type="button" data-aisb-wf-generate>Generate wireframe</button>
                      <button class="aisb-btn-secondary" type="button" data-aisb-wf-shuffle-page>Shuffle unlocked</button>
                      <button class="aisb-btn" type="button" data-aisb-wf-save>Save</button>
                      <button class="aisb-btn-secondary" type="button" data-aisb-wf-compile>Compile JSON</button>
                    </div>
                  </div>

                  <div class="aisb-wf-status" data-aisb-wf-status></div>
                  <div class="aisb-wf-sections" data-aisb-wf-sections></div>

                  <details class="aisb-wf-raw">
                    <summary>Compiled Bricks JSON (latest)</summary>
                    <pre class="aisb-pre" data-aisb-wf-compiled></pre>
                  </details>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div><!-- /Step 2 panel -->
        <?php endif; ?>
      </div>
    <?php
    return ob_get_clean();
  }

    public function enqueue_assets() {
    if (!$this->current_page_has_shortcode('ai_sitemap_builder') && !$this->current_page_has_shortcode('my-projects')) return;
    $this->enqueue_assets_for_shortcode();
  }

  private function get_settings(): array{
    // If your settings class exists and has a static getter, use it
    if (class_exists('AISB_Settings') && method_exists('AISB_Settings', 'get_settings')) {
        $settings = AISB_Settings::get_settings();
        return is_array($settings) ? $settings : [];
    }

    // Fallback to wp_options (adjust option key if yours differs)
    $settings = get_option(AISB_Plugin::OPT_KEY, []);
    return is_array($settings) ? $settings : [];
}


  private function enqueue_assets_for_shortcode() {
    if (self::$assets_enqueued) return;
    self::$assets_enqueued = true;

    $handle = 'aisb-frontend';
    wp_register_script($handle, false, [], AISB_VERSION, true);
    wp_enqueue_script($handle);

    $settings = $this->get_settings();

    wp_localize_script($handle, 'AISB', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce(AISB_Plugin::NONCE_ACTION),
      'action'  => AISB_Plugin::AJAX_ACTION,
      'actionAddPage' => AISB_Plugin::AJAX_ADD_PAGE,
      'actionGetLatestSitemap' => AISB_Plugin::AJAX_GET_LATEST_SITEMAP,
      'actionGetSitemapById'   => AISB_Plugin::AJAX_GET_SITEMAP_BY_ID,
      'maxPromptChars' => 4000,
      'demoMode' => empty($settings['api_key']) ? 1 : 0,
      'sectionTypes' => $this->section_types(),
      'actionSaveVersion' => AISB_Plugin::AJAX_SAVE_SITEMAP_VERSION,
    ]);

    wp_register_style('aisb-style', false, [], AISB_VERSION);
    wp_enqueue_style('aisb-style');
    wp_add_inline_style('aisb-style', $this->frontend_css());

    wp_add_inline_script($handle, $this->frontend_js(), 'after');
  }

  private function current_page_has_shortcode($shortcode) {
    if (!is_singular()) return false;
    global $post;
    if (!$post || empty($post->post_content)) return false;
    return has_shortcode($post->post_content, $shortcode);
  }

  public function section_types():array {
    $types = [
      'Banner Section',
      'Blog Sections',
      'Career Sections',
      'Category Filters',
      'Contact Sections',
      'Content Sections',
      'CTA Sections',
      'Event Sections',
      'FAQ Sections',
      'Feature Sections',
      'Footers',
      'Gallery Sections',
      'Headers',
      'Hero Sections',
      'Intro Sections',
      'Logo Sections',
      'Megamenu Sections - part of header section',
      'Portfolio Sections',
      'Pricing Sections',
      'Process Sections',
      'Products Sections',
      'Property Sections',
      'Single Event Sections',
      'Single Portfolio Sections',
      'Single Post Hero',
      'Single Post Sections',
      'Single Product Sections',
      'Single Property Sections',
      'Single Team Sections',
      'Team Sections',
      'Testimonial Sections',
      'Timeline Sections',
    ];

    // Allow overriding via filter, but guarantee an array return
    $filtered = apply_filters('aisb_section_types', $types);

    return is_array($filtered) ? array_values($filtered) : $types;
  }

    private function frontend_css() {
    return <<<CSS
.aisb-wrap{font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial; max-width: 100%; margin: 24px auto; padding: 0 14px;}
.aisb-header{margin: 0 0 14px 0;text-align: center;}
.aisb-steps{display:flex; gap:10px; justify-content:center; align-items:center; flex-wrap:wrap; margin: 10px 0 16px 0;}
.aisb-step-tab{display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border:1px solid #e6e6e6; border-radius:999px; background:#fff; color:#111; text-decoration:none; font-size:13px; font-weight:600;}
.aisb-step-tab:hover{background:#fafafa;}
.aisb-step-tab.is-active{border-color:#111;}
.aisb-badge{display:inline-block; font-size:12px; padding:6px 10px; border-radius:999px; background:#111; color:#fff; opacity:.9; margin-bottom:10px;}
.aisb-title{margin:0 0 6px 0; font-size:28px; line-height:1.15;}
.aisb-subtitle{margin:0; color:#555; font-size:14px;}
.aisb-card{background:#fff; border:1px solid rgba(0,0,0,.08); border-radius:16px; padding:16px; box-shadow:0 10px 30px rgba(0,0,0,.06); margin: 14px 0;}
.aisb-label{display:block; font-weight:600; margin-bottom:8px; font-size:14px;}
.aisb-textarea{width:100%; resize:vertical; border:1px solid rgba(0,0,0,.14); border-radius:12px; padding:12px; font-size:14px; line-height:1.4; outline:none;}
.aisb-textarea:focus{border-color: rgba(0,0,0,.35); box-shadow:0 0 0 3px rgba(0,0,0,.06);}
.aisb-row{display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:12px; flex-wrap:wrap;}
.aisb-btn{appearance:none; border:none; border-radius:12px; padding:10px 14px; font-weight:700; cursor:pointer; background:#111; color:#fff;}
.aisb-btn:hover{opacity:.92;}
.aisb-btn:disabled{opacity:.55; cursor:not-allowed;}
.aisb-btn-secondary{appearance:none; border:1px solid rgba(0,0,0,.14); border-radius:12px; padding:8px 12px; background:#fff; cursor:pointer; font-weight:600;}
.aisb-btn-secondary:hover{background:#fafafa;}
.aisb-plus{display:inline-block; font-weight:900; margin-right:6px;}
.aisb-hint{display:flex; align-items:center; gap:10px; color:#666; font-size:12px;}
.aisb-counter{padding:4px 8px; border-radius:999px; background:#f2f2f2;}
.aisb-demo-note{padding:4px 8px; border-radius:999px; background:#fff4cc; border:1px solid #ffe08a; color:#5a4500;}
.aisb-status{margin-top:10px; font-size:13px; color:#333;}
.aisb-status .aisb-error{color:#b00020; font-weight:600;}
.aisb-status .aisb-ok{color:#0b6b2f; font-weight:600;}

.aisb-output-head{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;}
.aisb-output-title{margin:0; font-size:18px;}
.aisb-actions{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
.aisb-summary{margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;}
.aisb-pill{display:inline-flex; gap:8px; align-items:center; padding:8px 10px; border-radius:999px; background:#f6f6f6; border:1px solid rgba(0,0,0,.06); font-size:12px;}
.aisb-pill strong{font-size:12px;}

.aisb-output-card{
  height: 100vh;
  display:flex;
  flex-direction:column;
  overflow:hidden;
}

.aisb-workspace{
  margin-top:14px;
  display:flex;
  gap:12px;
  flex:1 1 auto;
  min-height: 0; /* allows children to scroll properly */
}

.aisb-detail-panel{
  width:350px;
  flex: 0 0 350px;
  border:1px solid rgba(0,0,0,.08);
  border-radius:16px;
  overflow:hidden;
  background:#fff;
  display:flex;
  flex-direction:column;
  min-height:0;
}
.aisb-detail-head{padding:14px; background:linear-gradient(180deg,#fafafa,#fff); border-bottom:1px solid rgba(0,0,0,.06);}
.aisb-detail-title{font-weight:900; font-size:14px; margin:0;}
.aisb-detail-sub{margin-top:6px; color:#666; font-size:12px;}
.aisb-detail-body{
  padding:14px;
  overflow:auto; /* panel scroll */
  min-height:0;
}

.aisb-canvas-wrap{
  flex: 1 1 auto;
  min-width: 0;
  min-height: 0;
}
.aisb-canvas{
  position:relative;
  border:1px solid rgba(0,0,0,.08);
  border-radius:16px;
  overflow:hidden;
  height: 100%;
  min-height: 0;
  background:
    radial-gradient(circle at 1px 1px, rgba(0,0,0,.08) 1px, transparent 1px) 0 0 / 18px 18px,
    #fff;
}
.aisb-canvas-hint{
  position:absolute;
  left:12px;
  bottom:10px;
  font-size:12px;
  color:#666;
  background:rgba(255,255,255,.82);
  border:1px solid rgba(0,0,0,.08);
  padding:6px 10px;
  border-radius:999px;
  pointer-events:none;
}
.aisb-viewport{position:absolute; left:0; top:0; width:100%; height:100%; transform-origin: 0 0;}
.aisb-edges{position:absolute; left:0; top:0; width:100%; height:100%; overflow:visible; pointer-events:none;}
.aisb-nodes{position:absolute; left:0; top:0; width:100%; height:100%;}
.aisb-node-card{
  position:absolute;
  width: 260px;
  background:#fff;
  border:1px solid rgba(0,0,0,.10);
  border-radius:16px;
  box-shadow:0 10px 25px rgba(0,0,0,.07);
  user-select:none;
}
.aisb-node-card.is-active{outline:3px solid rgba(0,0,0,.18);}
.aisb-node-head{padding:12px 12px 10px 12px; border-bottom:1px solid rgba(0,0,0,.06); display:flex; gap:10px; align-items:flex-start;}
.aisb-node-title{font-weight:800; font-size:14px; line-height:1.15; margin:0;}
.aisb-node-slug{margin-top:6px; font-size:12px; color:#666;}
.aisb-node-pill{margin-left:auto; font-size:11px; padding:6px 8px; border-radius:999px; background:#f4f4f4; border:1px solid rgba(0,0,0,.06); white-space:nowrap;}
.aisb-node-body{padding:10px 12px 12px 12px;}
.aisb-section-mini{border:1px solid rgba(0,0,0,.08); border-radius:12px; padding:8px 9px; margin-top:8px;}
.aisb-section-mini:first-child{margin-top:0;}
.aisb-section-mini h4{margin:0; font-size:12px;}
.aisb-section-mini p{margin:4px 0 0 0; font-size:11px; color:#555; line-height:1.35;}

.aisb-section-mini{
  position:relative;
}

.aisb-section-mini[draggable="true"]{
  cursor:default;
}
.aisb-section-mini.is-mini-active{
  border: 2px solid rgba(0, 0, 0, .9);
}

.aisb-sec-accordion{
  border:1px solid rgba(0,0,0,.08);
  border-radius:14px;
  padding:10px;
  background:#fff;
  transition: border-color .15s ease, box-shadow .15s ease;
}

.aisb-sec-accordion[open] {
  border: 2px solid rgba(0, 0, 0, .9);
  box-shadow: 0 6px 18px rgba(0,0,0,.08);
}

.aisb-sec-summary{
  list-style:none;
  display:flex;
  align-items:center;
  gap:8px;
  cursor:pointer;
}

.aisb-sec-summary::-webkit-details-marker{ display:none; }

.aisb-sec-body{
  margin-top:10px;
}

.aisb-sec-summary{
  list-style:none;
  cursor:pointer;
  display:flex;
  align-items:center;
  gap:8px;
  margin:0;
  padding:0;
}
.aisb-sec-summary::-webkit-details-marker{ display:none; }

.aisb-sec-accordion > .aisb-sec-summary{
  position: relative;
  padding-left: 18px;
}

.aisb-sec-accordion > .aisb-sec-summary::before{
  content: "▾";
  position: absolute;
  left: 0;
  top: 50%;
  transform: translateY(-50%) rotate(-90deg); /* collapsed */
  font-size: 12px;
  opacity: .8;
  transition: transform .15s ease;
}

.aisb-sec-accordion[open] > .aisb-sec-summary::before{
  transform: translateY(-50%) rotate(0deg); /* expanded */
}

.aisb-sec-summary-title{
  font-size:13px;
  font-weight:800;
  min-width:0;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}

.aisb-brief-grid{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:12px;
  margin-top:12px;
}

@media (max-width: 820px){
  .aisb-brief-grid{ grid-template-columns: 1fr; }
}

.aisb-field .aisb-help{
  margin-top:6px;
  font-size:12px;
  color:#666;
}

.aisb-select{
  width:100%;
  border:1px solid rgba(0,0,0,.14);
  border-radius:12px;
  padding:10px 12px;
  font-size:14px;
  outline:none;
  background:#fff;
}

.aisb-select:focus{
  border-color: rgba(0,0,0,.35);
  box-shadow:0 0 0 3px rgba(0,0,0,.06);
}

.aisb-sec-summary-meta{
  margin-left:auto;
  font-size:11px;
  color:#666;
  padding:4px 8px;
  border-radius:999px;
  background:#f2f2f2;
  border:1px solid rgba(0,0,0,.06);
  white-space:nowrap;
}

/* spacing inside expanded body */
.aisb-sec-body{ margin-top:10px; }


.aisb-mini-sec-head{
  display:flex;
  align-items:flex-start;
  gap:8px;
}

.aisb-mini-sec-handle{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:22px;
  height:22px;
  border-radius:9px;
  border:1px solid rgba(0,0,0,.10);
  background:#fff;
  cursor:grab;
  user-select:none;
  flex:0 0 auto;
  font-weight:900;
  line-height:1;
  margin-top:1px;
}

.aisb-section-mini.is-mini-dragging{
  opacity:.55;
}

.aisb-section-mini.is-mini-drop-target{
  outline:2px dashed rgba(0,0,0,.22);
  outline-offset:3px;
}

.aisb-mini-add-under{
  position:absolute;
  left:50%;
  bottom:-14px;                 /* slightly outside the card like Relume */
  transform:translateX(-50%);
  width:28px;
  height:28px;
  border-radius:10px;
  border:1px solid rgba(0,0,0,.12);
  background:#fff;
  display:flex;
  align-items:center;
  justify-content:center;
  font-weight:900;
  line-height:1;
  cursor:pointer;
  box-shadow:0 10px 25px rgba(0,0,0,.12);
  opacity:0;
  pointer-events:none;
  transition:opacity .12s ease, transform .12s ease;
  z-index:5;
}

.aisb-section-mini:hover .aisb-mini-add-under,
.aisb-section-mini.is-mini-active .aisb-mini-add-under{
  opacity:1;
  pointer-events:auto;
  transform:translateX(-50%) scale(1.02);
}

.aisb-mini-add-under:hover{
  background:#fafafa;
}

.aisb-node-card{
  overflow: visible;
}

.aisb-node-actions{display:flex; gap:8px; margin-top:10px;}
.aisb-mini-btn{appearance:none; border:1px solid rgba(0,0,0,.12); border-radius:12px; padding:7px 10px; background:#fff; cursor:pointer; font-weight:700; font-size:12px;}
.aisb-mini-btn:hover{background:#fafafa;}
.aisb-mini-btn.primary{background:#111;color:#fff;border-color:#111;}
.aisb-mini-btn.primary:hover{opacity:.92;}
.aisb-node-handle{cursor:grab;}
.aisb-node-card.is-dragging .aisb-node-handle{cursor:grabbing;}
.aisb-inline-form{margin-top:10px; padding:10px; border:1px dashed rgba(0,0,0,.18); border-radius:12px; background:#fcfcfc;}
.aisb-inline-form label{display:block; font-size:11px; font-weight:800; margin:0 0 6px 0;}
.aisb-inline-form input,.aisb-inline-form textarea{
  width:100%;
  border:1px solid rgba(0,0,0,.14);
  border-radius:10px;
  padding:8px 10px;
  font-size:12px;
  outline:none;
}
.aisb-inline-form textarea{resize:vertical; min-height:56px;}
.aisb-inline-row{display:flex; gap:8px; margin-top:8px; align-items:center;}
.aisb-inline-row .aisb-mini-btn{flex:0 0 auto;}
.aisb-inline-row .aisb-inline-note{font-size:11px;color:#666;margin-left:auto;}

.aisb-page-meta{margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; color:#666; font-size:12px;}
.aisb-meta-item{padding:4px 8px; border-radius:999px; background:#f2f2f2;}
.aisb-sections{margin:12px 0 0 0; padding:0; list-style:none; display:flex; flex-direction:column; gap:10px;}
.aisb-section{border:1px solid rgba(0,0,0,.08); border-radius:14px; padding:10px;}
.aisb-section h4{margin:0 0 6px 0; font-size:13px;}
.aisb-section p{margin:0 0 8px 0; font-size:12px; color:#555;}

.aisb-sections[data-aisb-sections-editor]{ position:relative; }

.aisb-section{
  position:relative;
}

.aisb-section.is-dragging{
  opacity:.55;
}

.aisb-section.is-drop-target{
  outline:2px dashed rgba(0,0,0,.22);
  outline-offset:4px;
}

.aisb-section .aisb-sec-headrow{
  display:flex;
  align-items:center;
  gap:8px;
  margin:0 0 6px 0;
}

.aisb-section .aisb-sec-title{
  font-size:13px;
  font-weight:800;
  margin:0;
}

.aisb-kc{display:flex; flex-wrap:wrap; gap:6px;}
.aisb-chip{font-size:11px; padding:4px 8px; border-radius:999px; background:#f7f7f7; border:1px solid rgba(0,0,0,.06);}
.aisb-seo{margin-top:12px; border-top:1px dashed rgba(0,0,0,.14); padding-top:12px;}
.aisb-seo-title{margin:0 0 8px 0; font-size:12px; color:#333;}
.aisb-seo-grid{display:grid; grid-template-columns:1fr; gap:8px; font-size:12px; color:#555;}

.aisb-edit-grid{display:grid; grid-template-columns:1fr; gap:10px; margin-top:10px;}
.aisb-edit-row{display:grid; gap:6px;}
.aisb-edit-row label{font-size:11px; font-weight:800; color:#333;}
.aisb-edit-input, .aisb-edit-textarea, .aisb-edit-select{
  width:100%;
  border:1px solid rgba(0,0,0,.14);
  border-radius:10px;
  padding:8px 10px;
  font-size:12px;
  outline:none;
  background:#fff;
  line-height: 12px;
}
.aisb-edit-textarea{resize:vertical; min-height:56px;}
.aisb-edit-select{appearance:auto;}
.aisb-edit-help{font-size:11px; color:#666; margin-top:6px;}

.aisb-pre{white-space:pre-wrap; word-break:break-word; background:#0b0b0b; color:#eaeaea; padding:12px; border-radius:12px; font-size:12px; overflow:auto;}
.aisb-raw summary{cursor:pointer; margin-top:14px; font-weight:800;}
CSS;
  }

  private function frontend_js() {
    // JS is identical to prior version EXCEPT:
    // - after successful renderAll(data), it scrolls to output card
    // - on reset it clears
    return <<<'JS'
(() => {
  const root = document.querySelector('[data-aisb]');
  if (!root || !window.AISB) return;

  const promptEl = root.querySelector('#aisb-prompt');
  const languagesEl = root.querySelector('#aisb-languages');
  const pageCountEl = root.querySelector('#aisb-pagecount');
  const btnGen = root.querySelector('[data-aisb-generate]');
  const statusEl = root.querySelector('[data-aisb-status]');
  const outWrap = root.querySelector('[data-aisb-output]');
  const rawEl = root.querySelector('[data-aisb-raw]');
  const summaryEl = root.querySelector('[data-aisb-summary]');
  const counterEl = root.querySelector('[data-aisb-counter]');
  const demoNoteEl = root.querySelector('[data-aisb-demo-note]');

  const btnCopy = root.querySelector('[data-aisb-copy]');
  const btnSave = root.querySelector('[data-aisb-save]');
  const btnReset = root.querySelector('[data-aisb-reset]');
  const btnFit = root.querySelector('[data-aisb-fit]');
  const btnZoomIn = root.querySelector('[data-aisb-zoomin]');
  const btnZoomOut = root.querySelector('[data-aisb-zoomout]');
  const btnAddPageTop = root.querySelector('[data-aisb-add-page]');

  const canvasEl = root.querySelector('[data-aisb-canvas]');
  const viewportEl = root.querySelector('[data-aisb-viewport]');
  const edgesSvg = root.querySelector('[data-aisb-edges]');
  const nodesEl = root.querySelector('[data-aisb-nodes]');

  const detailTitleEl = root.querySelector('[data-aisb-detail-title]');
  const detailSubEl = root.querySelector('[data-aisb-detail-sub]');
  const detailBodyEl = root.querySelector('[data-aisb-detail-body]');

  const SECTION_TYPES = Array.isArray(AISB.sectionTypes) ? AISB.sectionTypes : [];

  if (AISB.demoMode && demoNoteEl) demoNoteEl.style.display = 'inline-flex';

  const setStatus = (html) => { statusEl.innerHTML = html || ''; };
  const esc = (s) => (s ?? '').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
    .replaceAll('"','&quot;').replaceAll("'","&#039;");

  const deepClone = (obj) => JSON.parse(JSON.stringify(obj));

  const stripTransient = (obj) => {
    if (Array.isArray(obj)) return obj.map(stripTransient);
    if (obj && typeof obj === 'object') {
      const out = {};
      for (const k in obj) {
        if (!Object.prototype.hasOwnProperty.call(obj, k)) continue;
        if (k.startsWith('_')) continue; // UI-only/transient keys
        out[k] = stripTransient(obj[k]);
      }
      return out;
    }
    return obj;
  };

  const stableStringify = (obj) => {
    const norm = (v) => {
      if (Array.isArray(v)) return v.map(norm);
      if (v && typeof v === 'object') {
        const out = {};
        Object.keys(v).sort().forEach((k) => { out[k] = norm(v[k]); });
        return out;
      }
      return v;
    };
    return JSON.stringify(norm(obj));
  };

  const slugMap = (data) => {
    const pages = Array.isArray(data?.sitemap) ? data.sitemap : [];
    const map = {};
    pages.forEach((p) => {
      if (p && typeof p === 'object' && p.slug) map[p.slug] = p;
    });
    return map;
  };

  const computeAutoLabel = (baseline, current) => {
    const baseMap = slugMap(baseline || {});
    const curMap  = slugMap(current || {});
    const baseSlugs = new Set(Object.keys(baseMap));
    const curSlugs  = new Set(Object.keys(curMap));

    const added = Array.from(curSlugs).filter(s => !baseSlugs.has(s)).sort();
    const removed = Array.from(baseSlugs).filter(s => !curSlugs.has(s)).sort();

    if (added.length) return added.map(s => `+${s}`).join(' ');
    if (removed.length) return removed.map(s => `-${s}`).join(' ');

    const changed = [];
    Array.from(curSlugs).forEach((slug) => {
      if (!baseSlugs.has(slug)) return;
      const a = stripTransient(baseMap[slug]);
      const b = stripTransient(curMap[slug]);
      if (stableStringify(a) !== stableStringify(b)) changed.push(slug);
    });

    if (changed.length) return changed.sort().join(', ');
    return 'No changes';
  };


  const setLoading = (loading) => {
    btnGen.disabled = !!loading;
    btnGen.textContent = loading ? 'Generating…' : 'Generate sitemap';
  };

  const normalizeSlug = (s) => (s ?? '').toString().trim().replace(/^\/+/, '').replace(/\/+$/, '');
  const slugify = (s) => normalizeSlug((s ?? '').toString().trim().toLowerCase()
    .replace(/['"]/g,'')
    .replace(/[^a-z0-9]+/g,'-')
    .replace(/^-+|-+$/g,'')
  ) || ('page-' + Math.random().toString(16).slice(2,8));

  const coerceType = (type, name) => {
    if (SECTION_TYPES.includes(type)) return type;
    const n = (name || '').toString().trim().toLowerCase();
    if (n === 'navbar') return 'Headers';
    if (n === 'hero') return 'Hero Sections';
    if (n === 'footer') return 'Footers';
    if (n === 'cta') return 'CTA Sections';
    if (n === 'faq') return 'FAQ Sections';
    if (n === 'social proof') return 'Testimonial Sections';
    if (n === 'process') return 'Process Sections';
    if (n === 'services overview') return 'Feature Sections';
    if (n === 'blog hero') return 'Blog Sections';
    if (n === 'blog list') return 'Blog Sections';
    if (n === 'content') return 'Content Sections';
    return 'Content Sections';
  };

  const ensureRequiredSections = (page) => {
    const pt = (page.page_type || 'Other');
    const slug = (page.slug || '');
    const isBlogListing = pt === 'Blog' && !slug.includes('post');

    let sections = Array.isArray(page.sections) ? page.sections : [];
    const has = new Set(sections.map(s => (s.section_name || '').toString().toLowerCase()));

    const add = (name, purpose, kc, section_type) => {
      const k = name.toLowerCase();
      if (has.has(k)) return;
      sections.push({ section_name: name, section_type: coerceType(section_type, name), purpose, key_content: kc });
      has.add(k);
    };

    const normalizeSections = () => {
      sections = (Array.isArray(sections) ? sections : []).map(s => {
        const section_name = (s?.section_name ?? '').toString();
        const purpose = (s?.purpose ?? '').toString();
        const key_content = Array.isArray(s?.key_content) ? s.key_content : [];
        const section_type = coerceType((s?.section_type ?? '').toString().trim(), section_name);
        return { section_name, section_type, purpose, key_content };
      }).filter(s => s.section_name.trim() !== '');
    };

    normalizeSections();
    has.clear();
    sections.forEach(s => has.add((s.section_name || '').toLowerCase()));

    if (isBlogListing) {
      sections = [];
      has.clear();
      add('Navbar', 'Primary navigation and key CTAs.', ['Logo','Menu items','CTA button'], 'Headers');
      add('Blog Hero', 'Introduce the blog and highlight featured content.', ['Title','Intro','Featured posts'], 'Blog Sections');
      add('Blog List', 'List all blog posts with filters/search.', ['Post cards','Categories/tags','Pagination'], 'Blog Sections');
      add('Footer', 'Secondary navigation, trust, and contact details.', ['Links','Contact info','Legal','Social links'], 'Footers');
      page.sections = sections;
      return page;
    }

    //add('Navbar', 'Primary navigation and key CTAs.', ['Logo','Menu items','CTA button'], 'Headers');
    //add('Hero', 'Primary message and conversion intent above the fold.', ['Headline','Subheadline','Primary CTA','Supporting visual'], 'Hero Sections');
   // add('Footer', 'Secondary navigation, trust, and contact details.', ['Links','Contact info','Legal','Social links'], 'Footers');

    const min = 5, max = 10;
    const pads = [
      ['Services overview','Preview key offerings.',['Service cards','Benefits','CTA'],'Feature Sections'],
      ['Social proof','Build trust quickly.',['Testimonials','Logos','Ratings'],'Testimonial Sections'],
      ['Process','Explain how it works.',['Steps','Timeline','What to expect'],'Process Sections'],
      ['FAQ','Answer common objections.',['Pricing','Scope','Support'],'FAQ Sections'],
      ['CTA','Drive the next step.',['Call booking','Contact link','Offer summary'],'CTA Sections']
    ];
    for (const p of pads) {
      if (sections.length >= min) break;
      add(p[0], p[1], p[2], p[3]);
    }

    if (sections.length > max) {
      const req = ['navbar','hero','footer'];
      const reqArr = [];
      const other = [];
      for (const s of sections) {
        const k = (s.section_name || '').toString().toLowerCase();
        if (req.includes(k)) reqArr.push(s); else other.push(s);
      }
      sections = reqArr.concat(other.slice(0, max - reqArr.length));
    }

    page.sections = sections;
    return page;
  };

  const ensureHierarchy = (pages) => {
    let home = pages.find(p => (p.page_type === 'Home') || normalizeSlug(p.slug) === 'home');
    if (!home) {
      home = { page_title:'Home', nav_label:'Home', slug:'home', page_type:'Home', priority:'Core', parent_slug:null, sections:[], seo:{} };
      pages.unshift(home);
    }
    home.slug = 'home';
    home.parent_slug = null;
    ensureRequiredSections(home);

    pages.forEach(p => { p.slug = normalizeSlug(p.slug) || slugify(p.page_title || p.nav_label || 'page'); });

    const bySlug = {};
    pages.forEach(p => { bySlug[p.slug] = p; });

    pages.forEach(p => {
      if (p.slug === 'home' || p.page_type === 'Home') { p.parent_slug = null; return; }
      p.parent_slug = normalizeSlug(p.parent_slug) || 'home';
      if (p.parent_slug === p.slug) p.parent_slug = 'home';
      if (!bySlug[p.parent_slug]) p.parent_slug = 'home';
      ensureRequiredSections(p);
    });

    const visitedGlobal = new Set();
    const visiting = new Set();

    const dfsCheck = (slug) => {
      if (slug === 'home') return;
      if (visiting.has(slug)) {
        const node = bySlug[slug];
        if (node) node.parent_slug = 'home';
        return;
      }
      if (visitedGlobal.has(slug)) return;
      visitedGlobal.add(slug);
      visiting.add(slug);

      const node = bySlug[slug];
      if (node) {
        const parent = normalizeSlug(node.parent_slug || '');
        if (!parent || parent === slug || !bySlug[parent]) node.parent_slug = 'home';
        else dfsCheck(parent);
      }
      visiting.delete(slug);
    };

    pages.forEach(p => dfsCheck(p.slug));
    return pages;
  };

  const buildIndex = (pages) => {
    const bySlug = {};
    pages.forEach(p => {
      const slug = normalizeSlug(p.slug || '');
      if (!slug) return;
      bySlug[slug] = {
        ...p,
        slug,
        parent_slug: p.parent_slug ? normalizeSlug(p.parent_slug) : null,
        nav_label: p.nav_label || p.page_title || slug,
        _x: (p._x ?? null),
        _y: (p._y ?? null),
        _userMoved: (p._userMoved === true)
      };
    });
    return bySlug;
  };

  const buildTree = (bySlug) => {
    const childrenByParent = {};
    Object.values(bySlug).forEach(p => {
      const parent = p.parent_slug || null;
      if (!childrenByParent[parent]) childrenByParent[parent] = [];
      childrenByParent[parent].push(p);
    });

    const prioRank = { 'Core': 0, 'Support': 1, 'Optional': 2 };
    const sortFn = (a,b) => {
      const aHome = (a.page_type === 'Home') ? -1 : 0;
      const bHome = (b.page_type === 'Home') ? -1 : 0;
      if (aHome !== bHome) return aHome - bHome;
      const pa = prioRank[a.priority] ?? 9;
      const pb = prioRank[b.priority] ?? 9;
      if (pa !== pb) return pa - pb;
      return (a.nav_label || a.page_title || '').localeCompare((b.nav_label || b.page_title || ''));
    };

    Object.keys(childrenByParent).forEach(k => childrenByParent[k].sort(sortFn));

    const roots = (childrenByParent[null] || []).sort(sortFn);
    const walk = (node) => {
      const kids = childrenByParent[node.slug] || [];
      return { ...node, children: kids.map(walk) };
    };
    return roots.map(walk);
  };

  // Pan/zoom
  const view = { tx: 0, ty: 0, scale: 1 };
  const applyTransform = () => {
    viewportEl.style.transform = `translate(${view.tx}px, ${view.ty}px) scale(${view.scale})`;
    requestAnimationFrame(drawEdges);
  };
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

  const zoomTo = (newScale, clientX, clientY) => {
    const rect = canvasEl.getBoundingClientRect();
    const cx = clientX - rect.left;
    const cy = clientY - rect.top;

    const prev = view.scale;
    const next = clamp(newScale, 0.35, 2.2);
    if (Math.abs(next - prev) < 0.0001) return;

    const wx = (cx - view.tx) / prev;
    const wy = (cy - view.ty) / prev;

    view.scale = next;
    view.tx = cx - wx * next;
    view.ty = cy - wy * next;
    applyTransform();
  };

  let isPanning = false;
  let panStart = { x:0, y:0, tx:0, ty:0 };

  canvasEl.addEventListener('mousedown', (e) => {
    const onNode = e.target.closest('.aisb-node-card');
    if (onNode) return;
    isPanning = true;
    panStart = { x: e.clientX, y: e.clientY, tx: view.tx, ty: view.ty };
    canvasEl.style.cursor = 'grabbing';
  });

  window.addEventListener('mousemove', (e) => {
    if (!isPanning) return;
    const dx = e.clientX - panStart.x;
    const dy = e.clientY - panStart.y;
    view.tx = panStart.tx + dx;
    view.ty = panStart.ty + dy;
    applyTransform();
  });

  window.addEventListener('mouseup', () => {
    if (!isPanning) return;
    isPanning = false;
    canvasEl.style.cursor = '';
  });

  canvasEl.addEventListener('wheel', (e) => {
    // On trackpads, pinch-to-zoom typically comes through as a wheel event with ctrlKey=true.
    // Two-finger scrolling is ctrlKey=false.
    if (e.ctrlKey) {
      e.preventDefault(); // prevent browser page zoom
      const delta = -e.deltaY;
      const factor = delta > 0 ? 1.08 : 0.92;
      zoomTo(view.scale * factor, e.clientX, e.clientY);
      return;
    }

    // Two-finger scroll => pan the canvas
    e.preventDefault(); // keep the page from scrolling while cursor is over the canvas

    // Trackpads provide deltaX for horizontal scroll gestures too.
    // Natural scrolling: moving fingers down gives positive deltaY; we move viewport accordingly.
    view.tx -= e.deltaX;
    view.ty -= e.deltaY;
    applyTransform();
  }, { passive: false });

  btnZoomIn?.addEventListener('click', () => zoomTo(view.scale * 1.12, canvasEl.getBoundingClientRect().left + canvasEl.clientWidth/2, canvasEl.getBoundingClientRect().top + canvasEl.clientHeight/2));
  btnZoomOut?.addEventListener('click', () => zoomTo(view.scale * 0.88, canvasEl.getBoundingClientRect().left + canvasEl.clientWidth/2, canvasEl.getBoundingClientRect().top + canvasEl.clientHeight/2));

  let state = {
    projectId: null,
    sitemapId: null,
    version: 0,
    savingVersion: false,
    baselineData: null,
    data: null,
    pages: [],
    bySlug: {},
    tree: [],
    activeSlug: null,
    edges: [],
    openInlineFormFor: null,
  };

  const renderSummary = (data) => {
    const pages = Array.isArray(data.sitemap) ? data.sitemap : [];
    const name = data.website_name || 'Website';
    const goal = data.website_goal || '';
    const audiences = Array.isArray(data.primary_audiences) ? data.primary_audiences : [];
    const notes = Array.isArray(data.notes) ? data.notes : [];

    summaryEl.innerHTML = [
      `<div class="aisb-pill"><strong>Current version</strong> <span>v${esc(state.version || 0)}</span></div>`,
      `<div class="aisb-pill"><strong>Next save</strong> <span>v${esc((state.version || 0) + 1)}</span></div>`,
      `<div class="aisb-pill"><strong>Name</strong> <span>${esc(name)}</span></div>`,
      goal ? `<div class="aisb-pill"><strong>Goal</strong> <span>${esc(goal)}</span></div>` : '',
      `<div class="aisb-pill"><strong>Pages</strong> <span>${pages.length}</span></div>`,
      audiences.length ? `<div class="aisb-pill"><strong>Audience</strong> <span>${esc(audiences.slice(0,2).join(' · '))}${audiences.length>2?'…':''}</span></div>` : '',
      notes.length ? `<div class="aisb-pill"><strong>Note</strong> <span>${esc(notes[0] || '')}</span></div>` : '',
    ].filter(Boolean).join('');
  };
  
  const clearMiniActive = () => {
      nodesEl.querySelectorAll('.aisb-section-mini.is-mini-active')
        .forEach(el => el.classList.remove('is-mini-active'));
    };

  const setActive = (slug) => {
    state.activeSlug = slug;
    nodesEl.querySelectorAll('.aisb-node-card').forEach(el => {
      el.classList.toggle('is-active', el.dataset.slug === slug);
    });
    renderDetail(slug);
  };

  const writeBackRaw = () => {
    if (!state.data) return;
    if (Array.isArray(state.data.sitemap)) {
      const idx = state.data.sitemap.findIndex(p => normalizeSlug(p.slug) === state.activeSlug);
      if (idx >= 0) state.data.sitemap[idx] = { ...state.bySlug[state.activeSlug] };
    }
    rawEl.textContent = JSON.stringify(state.data, null, 2);
  };

  const persistActivePage = () => {
    const slug = state.activeSlug;
    if (!slug || !state.bySlug[slug]) return;

    const i = state.pages.findIndex(p => normalizeSlug(p.slug) === slug);
    if (i >= 0) state.pages[i] = { ...state.bySlug[slug] };

    if (state.data && Array.isArray(state.data.sitemap)) {
      const j = state.data.sitemap.findIndex(p => normalizeSlug(p.slug) === slug);
      if (j >= 0) state.data.sitemap[j] = { ...state.bySlug[slug] };
    }
    writeBackRaw();
  };

  const refreshCanvasCards = () => {
    renderCanvas({ skipLayout: true });
    requestAnimationFrame(drawEdges);
  };

  const renderDetail = (slug) => {
    const page = state.bySlug?.[slug];
    if (!page) {
      detailTitleEl.textContent = 'Select a page';
      detailSubEl.textContent = 'We’ll show sections + SEO for that page.';
      detailBodyEl.innerHTML = '';
      return;
    }

    const sections = Array.isArray(page.sections) ? page.sections : [];
    const seo = page.seo || {};

    detailTitleEl.textContent = page.page_title || page.nav_label || page.slug;
    detailSubEl.textContent = '/' + (page.slug || '');

    const metaPills = [
      `<span class="aisb-meta-item">${esc(page.priority || 'Support')}</span>`,
      `<span class="aisb-meta-item">${esc(page.page_type || 'Other')}</span>`,
      page.parent_slug ? `<span class="aisb-meta-item">Parent: ${esc(page.parent_slug)}</span>` : `<span class="aisb-meta-item">Top-level</span>`,
      `<span class="aisb-meta-item">${sections.length} sections</span>`
    ].join('');

    const optionsHtml = (selected) => {
      const safeSelected = (selected || '').toString();
      const opts = SECTION_TYPES.map(t => {
        const sel = (t === safeSelected) ? ' selected' : '';
        return `<option value="${esc(t)}"${sel}>${esc(t)}</option>`;
      }).join('');
      const needsFallback = safeSelected && !SECTION_TYPES.includes(safeSelected);
      const fallbackOpt = needsFallback ? `<option value="${esc(safeSelected)}" selected>${esc(safeSelected)} (legacy)</option>` : '';
      return fallbackOpt + opts;
    };

    const sectionsHtml = sections.map((sec, idx) => {
      const sName = sec.section_name || 'Section';
      const purpose = sec.purpose || '';
      const stype = coerceType(sec.section_type || '', sName);
      const kc = Array.isArray(sec.key_content) ? sec.key_content : [];
      const chips = kc.slice(0, 12).map(x => `<span class="aisb-chip">${esc(x)}</span>`).join('');
    
      return `
        <details class="aisb-sec-accordion" data-aisb-sec="${idx}" draggable="true">
            <summary class="aisb-sec-summary">
              <span class="aisb-sec-title">${esc(sName)}</span>
            </summary>
        
            <div class="aisb-sec-body">
              <div class="aisb-edit-grid">
                <div class="aisb-edit-row">
                  <label>Section title</label>
                  <input class="aisb-edit-input" type="text" value="${esc(sName)}" data-aisb-sec-field="section_name" />
                </div>
        
                <div class="aisb-edit-row">
                  <label>Section type</label>
                  <select class="aisb-edit-select" data-aisb-sec-field="section_type">
                    ${optionsHtml(stype)}
                  </select>
                </div>
        
                <div class="aisb-edit-row">
                  <label>Description</label>
                  <textarea class="aisb-edit-textarea" data-aisb-sec-field="purpose">${esc(purpose)}</textarea>
                </div>
              </div>
        
              ${kc.length ? `<div class="aisb-edit-help">Key content:</div><div class="aisb-kc">${chips}</div>` : ''}
            </div>
          </details>
      `;
    }).join('');

    const seoHtml = `
      <div class="aisb-seo">
        <div class="aisb-seo-title"><strong>SEO</strong></div>
        <div class="aisb-seo-grid">
          ${seo.primary_keyword ? `<div><strong>Primary:</strong> ${esc(seo.primary_keyword)}</div>` : ''}
          ${Array.isArray(seo.secondary_keywords) && seo.secondary_keywords.length ? `<div><strong>Secondary:</strong> ${esc(seo.secondary_keywords.slice(0,8).join(', '))}${seo.secondary_keywords.length>8?'…':''}</div>` : ''}
          ${seo.meta_title ? `<div><strong>Meta title:</strong> ${esc(seo.meta_title)}</div>` : ''}
          ${seo.meta_description ? `<div><strong>Meta description:</strong> ${esc(seo.meta_description)}</div>` : ''}
        </div>
      </div>
    `;

    detailBodyEl.innerHTML = `
      <div class="aisb-page-meta">${metaPills}</div>
      <div class="aisb-sections" data-aisb-sections-editor>${sectionsHtml}</div>
      ${seoHtml}
    `;
  };

  let editDebounce = null;
  detailBodyEl.addEventListener('input', (e) => {
    const target = e.target;
    if (!target) return;

    const field = target.getAttribute('data-aisb-sec-field');
    if (!field) return;

    const secLi = target.closest('[data-aisb-sec]');
    if (!secLi) return;

    const idx = parseInt(secLi.getAttribute('data-aisb-sec'), 10);
    if (!Number.isFinite(idx)) return;

    const slug = state.activeSlug;
    const page = state.bySlug?.[slug];
    if (!page || !Array.isArray(page.sections) || !page.sections[idx]) return;

    const val = (target.value ?? '').toString();

    if (field === 'section_name') {
      page.sections[idx].section_name = val;
      page.sections[idx].section_type = coerceType(page.sections[idx].section_type || '', val);
    } else if (field === 'purpose') {
      page.sections[idx].purpose = val;
    } else if (field === 'section_type') {
      page.sections[idx].section_type = coerceType(val, page.sections[idx].section_name || '');
    }

    persistActivePage();

    clearTimeout(editDebounce);
    editDebounce = setTimeout(() => {
      const h4 = secLi.querySelector('h4');
      if (h4 && field === 'section_name') h4.textContent = page.sections[idx].section_name || 'Section';
      refreshCanvasCards();
    }, 120);
  });
  
    // ---------- Section drag/drop reordering (within active page only) ----------
  const reorderArray = (arr, fromIdx, toIdx) => {
    if (!Array.isArray(arr)) return arr;
    const from = Number(fromIdx), to = Number(toIdx);
    if (!Number.isFinite(from) || !Number.isFinite(to)) return arr;
    if (from === to) return arr;
    if (from < 0 || from >= arr.length) return arr;
    if (to < 0 || to >= arr.length) return arr;

    const copy = arr.slice();
    const [moved] = copy.splice(from, 1);
    copy.splice(to, 0, moved);
    return copy;
  };
  
  const insertSectionUnder = (slug, idx) => {
      const page = state.bySlug?.[slug];
      if (!page || !Array.isArray(page.sections)) return null;
    
      const insertAt = Math.min(page.sections.length, Math.max(0, idx + 1));
    
      const newSection = {
        section_name: 'New section',
        section_type: 'Content Sections',
        purpose: '',
        key_content: []
      };
    
      page.sections.splice(insertAt, 0, newSection);
      return insertAt;
    };


  // We use event delegation because detail panel is re-rendered often
  let secDrag = { active: false, fromIdx: null };
  
        // ---------- Canvas card mini-section drag/drop reordering (within same page card) ----------
  let miniDrag = { active:false, slug:null, fromIdx:null };

  const clearMiniDropClasses = (withinCardEl = null) => {
    const scope = withinCardEl || nodesEl;
    scope.querySelectorAll('.aisb-section-mini.is-mini-drop-target').forEach(el => el.classList.remove('is-mini-drop-target'));
    scope.querySelectorAll('.aisb-section-mini.is-mini-dragging').forEach(el => el.classList.remove('is-mini-dragging'));
  };
  
  let miniJustDragged = false;
    nodesEl.addEventListener('dragend', () => { miniJustDragged = true; setTimeout(()=>miniJustDragged=false, 0); });
    
    const openDetailSection = (idx, { closeOthers = true, scroll = true } = {}) => {
      const container = detailBodyEl.querySelector('[data-aisb-sections-editor]');
      if (!container) return;
    
      const all = Array.from(container.querySelectorAll('.aisb-sec-accordion[data-aisb-sec]'));
      const target = container.querySelector(`.aisb-sec-accordion[data-aisb-sec="${idx}"]`);
      if (!target) return;
    
      if (closeOthers) {
        all.forEach(d => { if (d !== target) d.removeAttribute('open'); });
      }
    
      target.setAttribute('open', 'open');
    
      if (scroll) {
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    };
    
    nodesEl.addEventListener('click', (e) => {
      const btn = e.target?.closest?.('[data-aisb-add-under]');
      if (!btn) return;
    
      e.preventDefault();
      e.stopPropagation();
    
      const slug = (btn.getAttribute('data-aisb-mini-slug') || '').trim();
      const idx  = parseInt(btn.getAttribute('data-aisb-mini-idx') || '', 10);
      if (!slug || !Number.isFinite(idx)) return;
    
      // Insert section in the right page
      const newIdx = insertSectionUnder(slug, idx);
      if (newIdx === null) return;
    
      // If not active, activate page first so detail panel switches
      if (state.activeSlug !== slug) {
        setActive(slug);
      }
    
      // Persist and refresh UI
      persistActivePage();
      renderDetail(slug);
      renderCanvas({ skipLayout: true });
      requestAnimationFrame(drawEdges);
    
      // Highlight the newly inserted mini card & open the accordion in the detail panel
      requestAnimationFrame(() => {
        clearMiniActive();
    
        const newMini = nodesEl.querySelector(
          `.aisb-section-mini[data-aisb-mini-slug="${CSS.escape(slug)}"][data-aisb-mini-idx="${newIdx}"]`
        );
        if (newMini) newMini.classList.add('is-mini-active');
    
        openDetailSection(newIdx, { closeOthers: true, scroll: true });
      });

    });

    // Click a mini section in the canvas => highlight it + open matching accordion in left pane
    nodesEl.addEventListener('click', (e) => {
      // If we just finished a drag, ignore the click that follows
      if (typeof miniJustDragged !== 'undefined' && miniJustDragged) return;

      // Ignore clicks on the add-under button
      if (e.target?.closest?.('[data-aisb-add-under]')) return;

      // Ignore clicks on the drag handle itself
      if (e.target?.closest?.('.aisb-mini-sec-handle')) return;

      const miniEl = e.target?.closest?.('[data-aisb-mini-sec]');
      if (!miniEl) return;

      const slug = (miniEl.getAttribute('data-aisb-mini-slug') || '').trim();
      const idx  = parseInt(miniEl.getAttribute('data-aisb-mini-idx') || '', 10);
      if (!slug || !Number.isFinite(idx)) return;

      // Make the page active if needed (so the left pane shows the right page)
      if (state.activeSlug !== slug) {
        setActive(slug);
      }

      // Highlight the clicked mini section
      clearMiniActive();
      miniEl.classList.add('is-mini-active');

      // Open the matching accordion in the left pane
      // (ensure the detail panel is rendered before opening)
      requestAnimationFrame(() => {
        openDetailSection(idx, { closeOthers: true, scroll: true });
      });
    });


  const getMiniEl = (t) => t?.closest?.('[data-aisb-mini-sec]') || null;
  const getMiniIdx = (el) => {
    if (!el) return null;
    const n = parseInt(el.getAttribute('data-aisb-mini-idx'), 10);
    return Number.isFinite(n) ? n : null;
  };
  const getMiniSlug = (el) => (el?.getAttribute?.('data-aisb-mini-slug') || '').trim() || null;

  // Start drag ONLY from the mini handle
  nodesEl.addEventListener('dragstart', (e) => {
    const handle = e.target?.closest?.('.aisb-mini-sec-handle');
    if (!handle) {
      // prevent accidental drags from text
      e.preventDefault();
      return;
    }

    const miniEl = e.target?.closest?.('[data-aisb-mini-sec]');
    if (!miniEl) { e.preventDefault(); return; }

    const slug = getMiniSlug(miniEl);
    const fromIdx = getMiniIdx(miniEl);
    const page = slug ? state.bySlug?.[slug] : null;

    if (!slug || fromIdx === null || !page || !Array.isArray(page.sections)) {
      e.preventDefault();
      return;
    }

    miniDrag = { active:true, slug, fromIdx };
    miniEl.classList.add('is-mini-dragging');

    try { e.dataTransfer.setData('text/plain', `${slug}:${fromIdx}`); } catch (_) {}
    e.dataTransfer.effectAllowed = 'move';
  });

  const getSecLi = (t) => t?.closest?.('.aisb-section[data-aisb-sec]') || null;
  const getSecIdx = (li) => {
    if (!li) return null;
    const n = parseInt(li.getAttribute('data-aisb-sec'), 10);
    return Number.isFinite(n) ? n : null;
  };

  const clearDropClasses = () => {
    detailBodyEl.querySelectorAll('.aisb-section.is-drop-target').forEach(el => el.classList.remove('is-drop-target'));
    detailBodyEl.querySelectorAll('.aisb-section.is-dragging').forEach(el => el.classList.remove('is-dragging'));
  };

  detailBodyEl.addEventListener('dragover', (e) => {
    if (!secDrag.active) return;

    const li = getSecLi(e.target);
    if (!li) return;

    e.preventDefault(); // allow drop
    e.dataTransfer.dropEffect = 'move';

    clearDropClasses();
    li.classList.add('is-drop-target');
  });

  detailBodyEl.addEventListener('dragleave', (e) => {
    if (!secDrag.active) return;
    const li = getSecLi(e.target);
    if (!li) return;
    li.classList.remove('is-drop-target');
  });

  detailBodyEl.addEventListener('drop', (e) => {
    if (!secDrag.active) return;

    e.preventDefault();
    const li = getSecLi(e.target);
    const toIdx = getSecIdx(li);

    const slug = state.activeSlug;
    const page = state.bySlug?.[slug];

    if (!page || !Array.isArray(page.sections) || toIdx === null || secDrag.fromIdx === null) {
      clearDropClasses();
      secDrag = { active:false, fromIdx:null };
      return;
    }

    // Reorder within same page only
    const nextSections = reorderArray(page.sections, secDrag.fromIdx, toIdx);
    page.sections = nextSections;

    // Persist + rerender detail panel + refresh canvas + raw JSON
    persistActivePage();
    renderDetail(slug);         // will rebuild data-aisb-sec indexes
    refreshCanvasCards();       // so order is reflected on the canvas cards
    setActive(slug);            // keep selection consistent

    clearDropClasses();
    secDrag = { active:false, fromIdx:null };
  });

  detailBodyEl.addEventListener('dragend', () => {
    if (!secDrag.active) return;
    clearDropClasses();
    secDrag = { active:false, fromIdx:null };
  });



  const getEdgeList = () => {
    const edges = [];
    Object.values(state.bySlug).forEach(p => {
      if (!p.parent_slug) return;
      const parent = normalizeSlug(p.parent_slug);
      if (!parent) return;
      edges.push({ from: parent, to: p.slug });
    });
    return edges;
  };

  const nodeHtml = (page) => {
    const sections = Array.isArray(page.sections) ? page.sections : [];
    const slug = '/' + esc(page.slug || '');
    const title = esc(page.page_title || page.nav_label || page.slug);

    const sectionsHtml = sections.map((s, idx) => {
      const t = s.section_type ? ` · ${esc(s.section_type)}` : '';
      const title = esc(s.section_name || 'Section');
      const purpose = esc(s.purpose || '');
      return `
          <div class="aisb-section-mini"
               data-aisb-mini-sec
               data-aisb-mini-slug="${esc(page.slug)}"
               data-aisb-mini-idx="${idx}">
               
            <div class="aisb-mini-sec-head">
              <span class="aisb-mini-sec-handle" draggable="true" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>
              <div style="min-width:0">
                <h4 style="margin:0; font-size:12px;">${title}${t}</h4>
                <p style="margin:4px 0 0 0; font-size:11px; color:#555; line-height:1.35;">${purpose}</p>
              </div>
            </div>
        
            <!-- ✅ Hover “add section underneath” button -->
            <button type="button"
                    class="aisb-mini-add-under"
                    title="Add section underneath"
                    aria-label="Add section underneath"
                    data-aisb-add-under
                    data-aisb-mini-slug="${esc(page.slug)}"
                    data-aisb-mini-idx="${idx}">+</button>
          </div>
        `;
    }).join('');

  nodesEl.addEventListener('dragover', (e) => {
    if (!miniDrag.active) return;

    const miniEl = getMiniEl(e.target);
    if (!miniEl) return;

    const slug = getMiniSlug(miniEl);
    if (!slug || slug !== miniDrag.slug) return; // only within same card/page

    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';

    // Clear only within this card
    const cardEl = miniEl.closest('.aisb-node-card');
    clearMiniDropClasses(cardEl);

    miniEl.classList.add('is-mini-drop-target');
  });

  nodesEl.addEventListener('dragleave', (e) => {
    if (!miniDrag.active) return;
    const miniEl = getMiniEl(e.target);
    if (!miniEl) return;
    miniEl.classList.remove('is-mini-drop-target');
  });

  nodesEl.addEventListener('drop', (e) => {
    if (!miniDrag.active) return;

    const miniEl = getMiniEl(e.target);
    if (!miniEl) return;

    const slug = getMiniSlug(miniEl);
    const toIdx = getMiniIdx(miniEl);

    if (!slug || slug !== miniDrag.slug || toIdx === null || miniDrag.fromIdx === null) {
      clearMiniDropClasses();
      miniDrag = { active:false, slug:null, fromIdx:null };
      return;
    }

    const page = state.bySlug?.[slug];
    if (!page || !Array.isArray(page.sections)) {
      clearMiniDropClasses();
      miniDrag = { active:false, slug:null, fromIdx:null };
      return;
    }

    e.preventDefault();

    // Reorder in state
    const nextSections = reorderArray(page.sections, miniDrag.fromIdx, toIdx);
    page.sections = nextSections;

    // Persist to raw JSON + data.sitemap, etc.
    if (slug === state.activeSlug) {
      // If it's the active page, persistActivePage already writes back + raw
      persistActivePage();
      renderDetail(slug); // refresh indexes + keep detail in sync
    } else {
      // Persist non-active page into state.pages/state.data.sitemap and raw JSON
      const i = state.pages.findIndex(p => normalizeSlug(p.slug) === slug);
      if (i >= 0) state.pages[i] = { ...page };
      if (state.data?.sitemap && Array.isArray(state.data.sitemap)) {
        const j = state.data.sitemap.findIndex(p => normalizeSlug(p.slug) === slug);
        if (j >= 0) state.data.sitemap[j] = { ...page };
      }
      writeBackRaw();
    }

    // Re-render canvas cards (to update mini order) without re-layout
    renderCanvas({ skipLayout: true });
    requestAnimationFrame(drawEdges);

    // Keep selection as-is
    if (state.activeSlug) setActive(state.activeSlug);

    clearMiniDropClasses();
    miniDrag = { active:false, slug:null, fromIdx:null };
  });

  btnSave?.addEventListener('click', async () => {
    if (!state.projectId || !state.data) {
      setStatus('<div class="aisb-error">No project loaded.</div>');
      return;
    }

    // Prevent double-submits (multiple click handlers or impatient clicks)
    if (state.savingVersion) return;
    state.savingVersion = true;
    btnSave.disabled = true;

    try {
      const label = computeAutoLabel(state.baselineData, state.data);

      const form = new FormData();
      form.append('action', AISB.actionSaveVersion);
      form.append('nonce', AISB.nonce);
      form.append('project_id', state.projectId);
      form.append('label', label);
      form.append('status', 'edited');
      form.append('sitemap_json', JSON.stringify(state.data));

      try {
        const res = await fetch(AISB.ajaxUrl, { method:'POST', credentials:'same-origin', body: form });
        const json = await res.json();
        if (!res.ok || !json?.success) {
          setStatus('<div class="aisb-error">'+esc(json?.data?.message || 'Save failed')+'</div>');
          return;
        }
        state.version = parseInt(json.data.version, 10) || state.version;
        state.baselineData = deepClone(state.data);
        renderSummary(state.data);
        setStatus('<div class="aisb-ok">Saved as version v'+esc(state.version)+'</div>');
      } catch (e) {
        setStatus('<div class="aisb-error">'+esc(e.message || 'Save failed')+'</div>');
      }
    } finally {
      state.savingVersion = false;
      btnSave.disabled = false;
    }
  });;

  nodesEl.addEventListener('dragend', () => {
    if (!miniDrag.active) return;
    clearMiniDropClasses();
    miniDrag = { active:false, slug:null, fromIdx:null };
  });

    const isFormOpen = state.openInlineFormFor === page.slug;

    return `
      <div class="aisb-node-head aisb-node-handle">
        <div>
          <div class="aisb-node-title">${title}</div>
          <div class="aisb-node-slug">${slug}</div>
        </div>
      </div>
      <div class="aisb-node-body">
        ${sectionsHtml}
        <div class="aisb-node-actions">
          <button class="aisb-mini-btn" type="button" data-aisb-select>Open</button>
          <button class="aisb-mini-btn primary" type="button" data-aisb-add-child>Add child +</button>
        </div>

        ${isFormOpen ? `
          <div class="aisb-inline-form" data-aisb-inline-form>
            <label>New child page</label>
            <input type="text" placeholder="Page title" data-aisb-child-title />
            <div style="height:8px"></div>
            <textarea placeholder="Short description (what this page is for)" data-aisb-child-desc></textarea>
            <div class="aisb-inline-row">
              <button class="aisb-mini-btn primary" type="button" data-aisb-child-create>Create</button>
              <button class="aisb-mini-btn" type="button" data-aisb-child-cancel>Cancel</button>
              <div class="aisb-inline-note">Built with OpenAI</div>
            </div>
          </div>
        ` : ''}
      </div>
    `;
  };

  const getNodePos = (slug) => {
    const p = state.bySlug[slug];
    return { x: (p?._x ?? 0), y: (p?._y ?? 0) };
  };

  const setNodePos = (slug, x, y, markUserMoved = false) => {
    const p = state.bySlug[slug];
    if (!p) return;
    p._x = x;
    p._y = y;
    if (markUserMoved) p._userMoved = true;

    const idx = state.pages.findIndex(pp => normalizeSlug(pp.slug) === slug);
    if (idx >= 0) {
      state.pages[idx]._x = x;
      state.pages[idx]._y = y;
      if (markUserMoved) state.pages[idx]._userMoved = true;
    }
    if (state.data?.sitemap && Array.isArray(state.data.sitemap)) {
      const j = state.data.sitemap.findIndex(pp => normalizeSlug(pp.slug) === slug);
      if (j >= 0) {
        state.data.sitemap[j]._x = x;
        state.data.sitemap[j]._y = y;
        if (markUserMoved) state.data.sitemap[j]._userMoved = true;
      }
    }
  };

  const positionNodeEl = (el, slug) => {
    const pos = getNodePos(slug);
    el.style.left = `${pos.x}px`;
    el.style.top  = `${pos.y}px`;
  };

  const attachNodeInteractions = (cardEl, slug) => {
    cardEl.addEventListener('mousedown', (e) => { e.stopPropagation(); });

    cardEl.querySelector('[data-aisb-select]')?.addEventListener('click', (e) => {
      e.stopPropagation();
      setActive(slug);
    });

    cardEl.querySelector('[data-aisb-add-child]')?.addEventListener('click', (e) => {
      e.stopPropagation();
      state.openInlineFormFor = (state.openInlineFormFor === slug) ? null : slug;
      renderCanvas({ skipLayout: true });
      requestAnimationFrame(drawEdges);
    });

    const formEl = cardEl.querySelector('[data-aisb-inline-form]');
    if (formEl) {
      formEl.querySelector('[data-aisb-child-cancel]')?.addEventListener('click', (e) => {
        e.stopPropagation();
        state.openInlineFormFor = null;
        renderCanvas({ skipLayout: true });
        requestAnimationFrame(drawEdges);
      });

      formEl.querySelector('[data-aisb-child-create]')?.addEventListener('click', async (e) => {
        e.stopPropagation();
        const title = (formEl.querySelector('[data-aisb-child-title]')?.value || '').trim();
        const desc  = (formEl.querySelector('[data-aisb-child-desc]')?.value || '').trim();
        if (title.length < 2) { setStatus('<div class="aisb-error">Please enter a title.</div>'); return; }
        if (desc.length < 3) { setStatus('<div class="aisb-error">Please enter a short description.</div>'); return; }
        setStatus('Creating child page…');
        await addChildPage(slug, title, desc);
      });
    }

    const handle = cardEl.querySelector('.aisb-node-handle');
    let dragging = false;
    let dragStart = { x:0, y:0, nx:0, ny:0 };

    const onDown = (e) => {
      if (e.button !== 0) return;
      if (!e.target.closest('.aisb-node-handle')) return;
      e.stopPropagation();

      dragging = true;
      cardEl.classList.add('is-dragging');
      setActive(slug);

      const pos = getNodePos(slug);
      dragStart = { x: e.clientX, y: e.clientY, nx: pos.x, ny: pos.y };

      window.addEventListener('mousemove', onMove);
      window.addEventListener('mouseup', onUp);
    };

    const onMove = (e) => {
      if (!dragging) return;
      const dx = (e.clientX - dragStart.x) / view.scale;
      const dy = (e.clientY - dragStart.y) / view.scale;
      setNodePos(slug, dragStart.nx + dx, dragStart.ny + dy, true);
      positionNodeEl(cardEl, slug);
      requestAnimationFrame(drawEdges);
    };

    const onUp = () => {
      if (!dragging) return;
      dragging = false;
      cardEl.classList.remove('is-dragging');
      window.removeEventListener('mousemove', onMove);
      window.removeEventListener('mouseup', onUp);
    };

    handle?.addEventListener('mousedown', onDown);
  };

  const layoutTreeTidy = async () => {
    const roots = state.tree || [];
    if (!roots.length) return;

    const CARD_W = 260;
    const GAP_X = 70;
    const STEP_X = CARD_W + GAP_X;

    const START_X = 80;
    const START_Y = 30;
    const GAP_Y = 90;

    const subtreeUnits = new Map();
    const computeUnits = (node) => {
      const kids = node.children || [];
      if (!kids.length) { subtreeUnits.set(node.slug, 1); return 1; }
      let sum = 0;
      kids.forEach(k => { sum += computeUnits(k); });
      subtreeUnits.set(node.slug, Math.max(1, sum));
      return Math.max(1, sum);
    };

    const desiredX = new Map();
    const assignX = (node, unitStart) => {
      const units = subtreeUnits.get(node.slug) || 1;
      const centerUnit = unitStart + units / 2;
      desiredX.set(node.slug, START_X + centerUnit * STEP_X);

      let cursor = unitStart;
      (node.children || []).forEach(child => {
        const cu = subtreeUnits.get(child.slug) || 1;
        assignX(child, cursor);
        cursor += cu;
      });
    };

    let rootCursor = 0;
    roots.forEach(r => { rootCursor += computeUnits(r); });
    rootCursor = 0;
    roots.forEach(r => {
      const u = subtreeUnits.get(r.slug) || 1;
      assignX(r, rootCursor);
      rootCursor += u;
    });

    Object.values(state.bySlug).forEach(p => {
      if (p._userMoved) return;
      const x = desiredX.get(p.slug);
      if (typeof x === 'number') p._x = x;
    });

    renderCanvas({ skipLayout: true });
    await new Promise(r => requestAnimationFrame(r));

    const depthMap = new Map();
    const walkDepth = (n, d) => {
      if (!depthMap.has(d)) depthMap.set(d, []);
      depthMap.get(d).push(n.slug);
      (n.children || []).forEach(c => walkDepth(c, d+1));
    };
    roots.forEach(r => walkDepth(r, 0));

    const depths = Array.from(depthMap.keys()).sort((a,b)=>a-b);

    const rowY = new Map();
    rowY.set(0, START_Y);

    for (let i=1; i<depths.length; i++) {
      const prevD = depths[i-1];
      const prevY = rowY.get(prevD) ?? START_Y;
      let maxBottom = prevY;

      (depthMap.get(prevD) || []).forEach(slug => {
        const el = nodesEl.querySelector(`.aisb-node-card[data-slug="${CSS.escape(slug)}"]`);
        if (!el) return;
        const p = state.bySlug[slug];
        const y = (p?._y ?? prevY);
        const bottom = y + el.offsetHeight;
        if (bottom > maxBottom) maxBottom = bottom;
      });

      rowY.set(depths[i], maxBottom + GAP_Y);
    }

    depths.forEach(d => {
      const y = rowY.get(d) ?? START_Y;
      (depthMap.get(d) || []).forEach(slug => {
        const p = state.bySlug[slug];
        if (!p || p._userMoved) return;
        p._y = y;
      });
    });

    renderCanvas({ skipLayout: true });
    requestAnimationFrame(drawEdges);
  };

  const getNodeAnchor = (slug, which) => {
    const el = nodesEl.querySelector(`.aisb-node-card[data-slug="${CSS.escape(slug)}"]`);
    if (!el) return null;
    const x = (el.offsetLeft || 0);
    const y = (el.offsetTop || 0);
    const w = el.offsetWidth || 0;
    const h = el.offsetHeight || 0;
    if (which === 'top') return { x: x + w/2, y: y };
    return { x: x + w/2, y: y + h };
  };

  const orthoPath = (a, b) => {
    const midY = a.y + Math.max(24, (b.y - a.y) * 0.45);
    const mY = (b.y > a.y) ? midY : (a.y + 30);
    const p1 = { x: a.x, y: a.y };
    const p2 = { x: a.x, y: mY };
    const p3 = { x: b.x, y: mY };
    const p4 = { x: b.x, y: b.y };
    return `M ${p1.x} ${p1.y} L ${p2.x} ${p2.y} L ${p3.x} ${p3.y} L ${p4.x} ${p4.y}`;
  };

  const drawEdges = () => {
    if (!state.edges || !state.edges.length) { edgesSvg.innerHTML = ''; return; }
    const w = canvasEl.clientWidth;
    const h = canvasEl.clientHeight;
    edgesSvg.setAttribute('width', w);
    edgesSvg.setAttribute('height', h);
    edgesSvg.setAttribute('viewBox', `0 0 ${w} ${h}`);

    const parts = [];
    for (const e of state.edges) {
      const a = getNodeAnchor(e.from, 'bottom');
      const b = getNodeAnchor(e.to, 'top');
      if (!a || !b) continue;
      const d = orthoPath(a, b);
      parts.push(`<path d="${d}" fill="none" stroke="rgba(0,0,0,.25)" stroke-width="2" />`);
      parts.push(`<circle cx="${a.x}" cy="${a.y}" r="3" fill="rgba(0,0,0,.35)" />`);
      parts.push(`<circle cx="${b.x}" cy="${b.y}" r="3" fill="rgba(0,0,0,.35)" />`);
    }
    edgesSvg.innerHTML = parts.join('');
  };

  const renderCanvas = ({skipLayout=false} = {}) => {
    nodesEl.innerHTML = '';

    Object.values(state.bySlug).forEach((p) => {
      const card = document.createElement('div');
      card.className = 'aisb-node-card';
      card.dataset.slug = p.slug;
      card.innerHTML = nodeHtml(p);

      if (p._x == null) p._x = 80 + Math.random()*20;
      if (p._y == null) p._y = 30 + Math.random()*20;

      positionNodeEl(card, p.slug);

      if (state.activeSlug === p.slug) card.classList.add('is-active');

      attachNodeInteractions(card, p.slug);
      nodesEl.appendChild(card);
    });

    state.edges = getEdgeList();
    requestAnimationFrame(drawEdges);

    if (!skipLayout) layoutTreeTidy();
  };

  const fitToView = () => {
    const cards = Array.from(nodesEl.querySelectorAll('.aisb-node-card'));
    if (!cards.length) return;

    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    cards.forEach(el => {
      minX = Math.min(minX, el.offsetLeft);
      minY = Math.min(minY, el.offsetTop);
      maxX = Math.max(maxX, el.offsetLeft + el.offsetWidth);
      maxY = Math.max(maxY, el.offsetTop + el.offsetHeight);
    });

    const padding = 50;
    const worldW = (maxX - minX) + padding*2;
    const worldH = (maxY - minY) + padding*2;

    const cw = canvasEl.clientWidth;
    const ch = canvasEl.clientHeight;

    const scale = clamp(Math.min(cw / worldW, ch / worldH), 0.35, 1.4);
    view.scale = scale;

    const centerWorldX = (minX + maxX)/2;
    const centerWorldY = (minY + maxY)/2;
    view.tx = cw/2 - centerWorldX * scale;
    view.ty = ch/2 - centerWorldY * scale;

    applyTransform();
  };

  btnFit?.addEventListener('click', fitToView);

  const openAddPageFormOnHome = () => {
    state.openInlineFormFor = (state.openInlineFormFor === 'home') ? null : 'home';
    renderCanvas({ skipLayout: true });
    requestAnimationFrame(drawEdges);
  };
  btnAddPageTop?.addEventListener('click', openAddPageFormOnHome);

  const addChildPage = async (parentSlug, title, desc) => {
    const parent = state.bySlug[parentSlug];
    if (!parent) { setStatus('<div class="aisb-error">Parent not found.</div>'); return; }

    const form = new FormData();
    form.append('action', AISB.actionAddPage);
    form.append('nonce', AISB.nonce);
    form.append('parent_slug', parentSlug);
    form.append('title', title);
    form.append('desc', desc);

    const siteContext = {
      website_name: state.data?.website_name || '',
      website_goal: state.data?.website_goal || '',
      primary_audiences: state.data?.primary_audiences || [],
      notes: state.data?.notes || [],
      existing_pages: (state.pages || []).map(p => ({ slug:p.slug, page_title:p.page_title, page_type:p.page_type, parent_slug:p.parent_slug }))
    };
    form.append('site_context', JSON.stringify(siteContext));

    try {
      const res = await fetch(AISB.ajaxUrl, { method:'POST', credentials:'same-origin', body: form });
      const json = await res.json();
      if (!res.ok || !json || json.success !== true) {
        const msg = (json && json.data && json.data.message) ? json.data.message : 'Unexpected error.';
        const raw = (json && json.data && json.data.raw)
          ? ('<details><summary>Raw</summary><pre class="aisb-pre">'+esc(json.data.raw)+'</pre></details>')
          : '';
        setStatus('<div class="aisb-error">'+esc(msg)+'</div>' + raw);
        return;
      }

      const page = json.data.page;
      if (!page || typeof page !== 'object') {
        setStatus('<div class="aisb-error">Invalid add-page response.</div>');
        return;
      }

      page.slug = normalizeSlug(page.slug) || slugify(page.page_title || title);
      page.parent_slug = parentSlug || 'home';
      ensureRequiredSections(page);

      if (state.bySlug[page.slug]) page.slug = page.slug + '-' + Math.random().toString(16).slice(2,5);

      if (state.data && Array.isArray(state.data.sitemap)) state.data.sitemap.push(page);

      state.pages = ensureHierarchy(state.pages);
      state.bySlug = buildIndex(state.pages);
      state.tree = buildTree(state.bySlug);
      state.projectId = json.data.project_id || state.projectId;

      const parentEl = nodesEl.querySelector(`.aisb-node-card[data-slug="${CSS.escape(parentSlug)}"]`);
      const parentPos = getNodePos(parentSlug);
      const approxParentH = parentEl ? parentEl.offsetHeight : 220;

      const newP = state.bySlug[page.slug];
      if (newP && !newP._userMoved) {
        newP._x = parentPos.x;
        newP._y = parentPos.y + approxParentH + 90;
      }

      state.openInlineFormFor = null;

      renderSummary(state.data);
      rawEl.textContent = JSON.stringify(state.data, null, 2);
      renderCanvas();
      fitToView();
      setActive(page.slug);
      setStatus('<div class="aisb-ok">Child page created.</div>');
    } catch (e) {
      setStatus('<div class="aisb-error">'+esc(e.message || 'Request failed')+'</div>');
    }
  };

  // NEW: scroll to output after generation
  const scrollToOutput = () => {
    if (!outWrap) return;
    outWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const renderAll = (data) => {
    outWrap.style.display = 'flex'; // because output card is now a flex column container

    const pages = ensureHierarchy(Array.isArray(data.sitemap) ? data.sitemap : []);
    data.sitemap = pages;

    state.data = data;
    state.pages = pages;
    state.bySlug = buildIndex(pages);
    state.tree = buildTree(state.bySlug);
    state.edges = getEdgeList();

    // Treat freshly rendered data as the new baseline (current saved version)
    state.baselineData = deepClone(data);
    renderSummary(data);
    rawEl.textContent = JSON.stringify(data, null, 2);

    view.tx = 0; view.ty = 0; view.scale = 1;
    applyTransform();

    state.activeSlug = 'home';
    state.openInlineFormFor = null;

    renderCanvas();
    setActive('home');

    // scroll AFTER layout has been painted
    requestAnimationFrame(() => {
      scrollToOutput();
    });
  };

  const loadLatestForProject = async (projectId) => {
    const form = new FormData();
    form.append('action', AISB.actionGetLatestSitemap);
    form.append('nonce', AISB.nonce);
    form.append('project_id', projectId);

    const res = await fetch(AISB.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form });
    const json = await res.json();
    if (!res.ok || !json || json.success !== true) {
      const msg = (json && json.data && json.data.message) ? json.data.message : 'Could not load latest sitemap.';
      throw new Error(msg);
    }
    return json.data;
  };

  const loadSitemapById = async (sitemapId) => {
    const form = new FormData();
    form.append('action', AISB.actionGetSitemapById);
    form.append('nonce', AISB.nonce);
    form.append('sitemap_id', sitemapId);

    const res = await fetch(AISB.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form });
    const json = await res.json();
    if (!res.ok || !json || json.success !== true) {
      const msg = (json && json.data && json.data.message) ? json.data.message : 'Could not load sitemap version.';
      throw new Error(msg);
    }
    return json.data;
  };

  const autoLoadFromUrl = async () => {
    try {
      const params = new URLSearchParams(window.location.search || '');
      const pid = parseInt(params.get('aisb_project') || '', 10);
      const sid = parseInt(params.get('aisb_sitemap') || '', 10);

      if (!Number.isFinite(pid) && !Number.isFinite(sid)) return;

      setLoading(true);
      setStatus('Loading selected sitemap…');

      let payload = null;
      if (Number.isFinite(sid) && sid > 0) {
        payload = await loadSitemapById(sid);
      } else if (Number.isFinite(pid) && pid > 0) {
        payload = await loadLatestForProject(pid);
      }

      if (!payload || !payload.data) return;

      state.projectId = payload.project_id ? parseInt(payload.project_id, 10) : (Number.isFinite(pid) ? pid : null);
      state.sitemapId = payload.sitemap_id ? parseInt(payload.sitemap_id, 10) : (Number.isFinite(sid) ? sid : null);
      state.version = payload.version ? parseInt(payload.version, 10) : 0;

      // Optional: hydrate prompt area with saved brief (we do not fetch it here).
      renderAll(payload.data);
      setStatus('<div class="aisb-ok">Loaded.</div>');
    } catch (e) {
      setStatus('<div class="aisb-error">'+esc(e.message || 'Failed to load sitemap')+'</div>');
    } finally {
      setLoading(false);
    }
  };

  const doGenerate = async () => {
    const prompt = (promptEl.value || '').trim();
    const languages = languagesEl
      ? Array.from(languagesEl.selectedOptions).map(o => (o.value || '').trim()).filter(Boolean)
      : [];
    
    const pageCount = pageCountEl ? (pageCountEl.value || '').trim() : '';
    if (prompt.length < 10) {
      setStatus('<div class="aisb-error">Please add a bit more detail (at least 10 characters).</div>');
      return;
    }
    if (prompt.length > AISB.maxPromptChars) {
      setStatus('<div class="aisb-error">Prompt is too long.</div>');
      return;
    }

    setStatus('Generating sitemap and canvas layout… Please be patient, this can take up to 5 minutes.');
    setLoading(true);

    const form = new FormData();
    form.append('action', AISB.action);
    form.append('nonce', AISB.nonce);
    form.append('prompt', prompt);
    form.append('languages', JSON.stringify(languages));
    form.append('page_count', pageCount);
    form.append('project_id', state.projectId || '');

    try {
      const res = await fetch(AISB.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form });
      const text = await res.text(); // read raw response first

      let json = null;
        try {
          json = JSON.parse(text);
        } catch (e) {
          // Show first part of HTML/text so you can debug
          setStatus(
            '<div class="aisb-error">Server did not return JSON. First 300 chars:</div>' +
            '<pre class="aisb-pre">' + esc(text.slice(0, 300)) + '</pre>'
          );
          setLoading(false);
          return;
        }
      
      if (!res.ok || !json || json.success !== true) {
        const msg = (json && json.data && json.data.message) ? json.data.message : 'Unexpected error.';
        const raw = (json && json.data && json.data.raw)
          ? ('<details><summary>Raw</summary><pre class="aisb-pre">'+esc(json.data.raw)+'</pre></details>')
          : '';
        setStatus('<div class="aisb-error">'+esc(msg)+'</div>' + raw);
        setLoading(false);
        return;
      }

      // Persist server-side IDs so actions like "Save version" work.
      // Backend returns: { project_id, sitemap_id, version, data, demo }
      if (json.data && typeof json.data.project_id !== 'undefined') {
        const pid = parseInt(json.data.project_id, 10);
        state.projectId = Number.isFinite(pid) && pid > 0 ? pid : null;
      }
      if (json.data && typeof json.data.sitemap_id !== 'undefined') {
        const sid = parseInt(json.data.sitemap_id, 10);
        state.sitemapId = Number.isFinite(sid) && sid > 0 ? sid : null;
      }
      if (json.data && typeof json.data.version !== 'undefined') {
        const v = parseInt(json.data.version, 10);
        state.version = Number.isFinite(v) && v > 0 ? v : 0;
      }

      const data = json.data.data;
      renderAll(data);
      setStatus('<div class="aisb-ok">Done.</div>');
    } catch (e) {
      setStatus('<div class="aisb-error">'+esc(e.message || 'Request failed')+'</div>');
    } finally {
      setLoading(false);
    }
  };

  promptEl.addEventListener('input', () => {
    const len = (promptEl.value || '').length;
    counterEl.textContent = len + ' / ' + AISB.maxPromptChars;
  });

  btnGen.addEventListener('click', doGenerate);

  btnCopy.addEventListener('click', async () => {
    const txt = rawEl.textContent || '';
    try {
      await navigator.clipboard.writeText(txt);
      setStatus('<div class="aisb-ok">Copied JSON to clipboard.</div>');
    } catch (e) {
      setStatus('<div class="aisb-error">Could not copy. Select and copy manually.</div>');
    }
  });

  btnReset.addEventListener('click', () => {
    outWrap.style.display = 'none';
    rawEl.textContent = '';
    summaryEl.innerHTML = '';
    nodesEl.innerHTML = '';
    edgesSvg.innerHTML = '';
    state = { projectId:null, sitemapId:null, version:0, baselineData:null, data:null, pages:[], bySlug:{}, tree:[], activeSlug:null, edges:[], openInlineFormFor:null };
    detailTitleEl.textContent = 'Select a page';
    detailSubEl.textContent = 'We’ll show sections + SEO for that page.';
    detailBodyEl.innerHTML = '';
    setStatus('');
  });

  // Auto-load a selected project/version when arriving from [my-projects]
  // (expects GET params: ?aisb_project=123&aisb_sitemap=456)
  autoLoadFromUrl();

  const ro = new ResizeObserver(() => requestAnimationFrame(drawEdges));
  ro.observe(canvasEl);
})();
JS;
  }
}