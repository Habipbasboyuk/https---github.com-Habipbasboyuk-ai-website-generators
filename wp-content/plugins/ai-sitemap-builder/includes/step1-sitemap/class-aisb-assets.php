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
        <?php if (!empty($atts['title'])) : ?>
          <h3 class="aisb-projects-title"><?php echo esc_html($atts['title']); ?></h3>
        <?php endif; ?>

        <?php if (empty($project_ids)) : ?>
          <p class="aisb-projects-empty">You don't have any projects yet. Generate a sitemap first to create your first project.</p>
        <?php else : ?>
          <div class="aisb-projects-grid">
            <?php foreach ($project_ids as $pid) : ?>
              <?php
                $title = get_the_title($pid);
                $brief = (string) get_post_meta($pid, 'aisb_project_brief', true);
                $versions = $versions_by_project[$pid] ?? [];
                $latest = $versions[0]['id'] ?? 0;
              ?>
              <div class="aisb-project-card">
                <div class="aisb-project-card-head">
                  <div>
                    <div class="aisb-project-card-title"><?php echo esc_html($title ?: ('Project #' . (int)$pid)); ?></div>
                    <?php if (!empty($brief)) : ?>
                      <div class="aisb-project-card-brief"><?php echo esc_html($brief); ?></div>
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
                    <div class="aisb-project-card-actions">
                      <a class="aisb-btn-secondary" href="<?php echo esc_url($latest_url); ?>">Open sitemap</a>
                      <a class="aisb-btn" href="<?php echo esc_url($latest_wf_url); ?>">Wireframes</a>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="aisb-project-versions">
                  <div class="aisb-project-versions-label">Versions</div>
                  <?php if (empty($versions)) : ?>
                    <div class="aisb-project-versions-empty">No versions yet.</div>
                  <?php else : ?>
                    <div class="aisb-project-versions-list">
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
                        <span class="aisb-project-version-group">
                          <a href="<?php echo esc_url($v_url); ?>" class="aisb-project-version-link">
                            <?php echo esc_html($v_label); ?>
                          </a>
                          <a href="<?php echo esc_url($v_wf_url); ?>" title="Wireframes" class="aisb-project-version-wf">WF</a>
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
    $tab3_url = add_query_arg(['aisb_step' => 3], $base_url);

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
          <a class="aisb-step-tab <?php echo $step === 2 ? 'is-active' : ''; ?>" href="<?php echo esc_url($tab2_url); ?>" data-aisb-step2-tab>Step 2 · Wireframes</a>
          <a class="aisb-step-tab <?php echo $step === 3 ? 'is-active' : ''; ?>" href="<?php echo esc_url($tab3_url); ?>">Step 3 · Style Guide</a>
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
              <button class="aisb-btn" type="button" data-aisb-approve style="display:none;">
                Ziet er goed uit? Genereer sectie-inhoud →
              </button>
              <button class="aisb-btn-secondary" type="button" data-aisb-add-page>
                <span class="aisb-plus">+</span> Add page
              </button>
              <button class="aisb-btn-secondary" type="button" data-aisb-fit>Fit</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-zoomout>−</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-zoomin>+</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-copy>Copy JSON</button>
              <button class="aisb-btn-secondary" type="button" data-aisb-save>Save version</button>
              <a class="aisb-btn" data-aisb-go-wireframes href="#" style="display:none; text-decoration:none;">→ Wireframes</a>
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
                <h3 class="aisb-output-title">Wireframes</h3>
                <p class="aisb-subtitle">Relume-like preview · Brixies sections · fast skeleton rendering</p>
              </div>
            </div>

            <?php if (!is_user_logged_in()) : ?>
              <p>You must be logged in to use wireframes.</p>
            <?php elseif (!$project_id || !$sitemap_id) : ?>
              <div class="aisb-wf-no-project">
                <p class="aisb-wf-no-project-msg">Please select one of your projects below to start generating wireframes.</p>
                <div class="aisb-wf-no-project-inner">
                  <?php echo $this->render_my_projects_shortcode(['title' => '']); ?>
                </div>
              </div>
            <?php else : ?>

              <!-- Toolbar -->
              <div class="aisb-wf-toolbar">
                <div class="aisb-wf-toolbar-right">
                  <button class="aisb-btn generate-wireframe__all" type="button" data-aisb-wf-generate-all>Generate all</button>
                  <button class="aisb-btn" type="button" data-aisb-wf-save-all>Save all</button>
                  <a href="<?php echo esc_url(add_query_arg(['aisb_step' => 3], $base_url)); ?>" class="aisb-btn styleguide-link__button" data-aisb-wf-style>Style your wireframes</a>
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
        </div><!-- /Step 2 panel -->
        <?php endif; ?>

        <?php if ($step === 3) : ?>
        <div class="aisb-step-panel" data-aisb-step-panel="3">
          <div class="aisb-card" data-styleguide
               data-styleguide-project="<?php echo esc_attr($project_id); ?>">
            <div class="aisb-sg-head">
              <div>
                <h3 class="aisb-output-title">Style Guide</h3>
                <p class="aisb-subtitle">Brand colours · typography · component tokens</p>
              </div>
            </div>

            <?php AISB_Style_Guide::render_style_guide_html($project_id); ?>
          </div>
        </div><!-- /Step 3 panel -->
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

    $settings = $this->get_settings();

    // --- CSS (8 files, no dependencies between them) ---
    $css_files = [
      'aisb-base'       => 'css/base.css',
      'aisb-forms'      => 'css/forms.css',
      'aisb-buttons'    => 'css/buttons.css',
      'aisb-output'     => 'css/output.css',
      'aisb-canvas'     => 'css/canvas.css',
      'aisb-node-cards' => 'css/node-cards.css',
      'aisb-sections'   => 'css/sections.css',
      'aisb-frontend'   => 'frontend.css',
    ];
    foreach ($css_files as $handle => $file) {
      wp_enqueue_style($handle, AISB_PLUGIN_URL . 'assets/' . $file, [], AISB_VERSION);
    }

    // --- JS (5 files, loaded in dependency order) ---
    wp_enqueue_script(
      'aisb-app-init',
      AISB_PLUGIN_URL . 'assets/js/app-init.js',
      [],
      AISB_VERSION,
      true
    );

    // Localize on the first JS file so window.AISB is available before the others run.
    wp_localize_script('aisb-app-init', 'AISB', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce(AISB_Plugin::NONCE_ACTION),
      'action'  => AISB_Plugin::AJAX_ACTION,
      'actionAddPage' => AISB_Plugin::AJAX_ADD_PAGE,
      'actionFillSections' => AISB_Plugin::AJAX_FILL_SECTIONS,
      'actionGetLatestSitemap' => AISB_Plugin::AJAX_GET_LATEST_SITEMAP,
      'actionGetSitemapById'   => AISB_Plugin::AJAX_GET_SITEMAP_BY_ID,
      'maxPromptChars' => 4000,
      'demoMode' => empty($settings['api_key']) ? 1 : 0,
      'sectionTypes' => $this->section_types(),
      'actionSaveVersion' => AISB_Plugin::AJAX_SAVE_SITEMAP_VERSION,
    ]);

    wp_enqueue_script(
      'aisb-app-utils',
      AISB_PLUGIN_URL . 'assets/js/app-utils.js',
      ['aisb-app-init'],
      AISB_VERSION,
      true
    );

    wp_enqueue_script(
      'aisb-app-canvas',
      AISB_PLUGIN_URL . 'assets/js/app-canvas.js',
      ['aisb-app-utils'],
      AISB_VERSION,
      true
    );

    wp_enqueue_script(
      'aisb-app-ui',
      AISB_PLUGIN_URL . 'assets/js/app-ui.js',
      ['aisb-app-canvas'],
      AISB_VERSION,
      true
    );

    wp_enqueue_script(
      'aisb-app-main',
      AISB_PLUGIN_URL . 'assets/js/app-main.js',
      ['aisb-app-ui'],
      AISB_VERSION,
      true
    );
  }

  private function current_page_has_shortcode($shortcode) {
    if (!is_singular()) return false;
    global $post;
    if (!$post || empty($post->post_content)) return false;
    return has_shortcode($post->post_content, $shortcode);
  }

  public function section_types():array {
    return AISB_Enforcer::section_types();
  }
}
