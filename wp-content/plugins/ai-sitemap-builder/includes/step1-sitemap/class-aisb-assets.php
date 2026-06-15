<?php

if (!defined('ABSPATH')) exit;

/**
 * Laadt en rendert de frontend van de AI Sitemap Builder.
 *
 * Deze klasse heeft drie hoofdtaken:
 * - shortcodes tonen voor de builder en de projectlijst;
 * - de juiste HTML per stap opbouwen;
 * - CSS en JavaScript alleen laden wanneer ze nodig zijn.
 */
class AISB_Assets {

  /**
   * Houdt bij of de assets in deze request al zijn toegevoegd.
   */
  private static bool $assets_enqueued = false;

  /**
   * Rendert de shortcode [my-projects].
   *
   * Wat deze shortcode doet:
   * - toont de projecten van de ingelogde gebruiker;
   * - toont per project de opgeslagen sitemapversies;
   * - maakt links naar de builder of wireframes met de juiste GET-parameters.
   *
   * Shortcode-attributen:
   * - builder_url: pagina met [ai_sitemap_builder]. Leeg betekent huidige pagina.
   * - wireframes_url: pagina met wireframes. Leeg gebruikt dezelfde pagina als builder_url.
   * - title: titel boven de projectlijst.
   * - target_step: optionele stap waarnaar een projectlink direct moet openen.
   */
  public function render_my_projects_shortcode($atts = [], $content = null) {
    // Alleen ingelogde gebruikers mogen hun eigen projecten bekijken.
    if (!is_user_logged_in()) {
      return '<div class="aisb-wrap"><div class="aisb-card"><p>You must be logged in to view your projects.</p></div></div>';
    }

    // Lees de shortcode-instellingen en vul eenvoudige standaardwaarden in.
    $atts = shortcode_atts([
      'builder_url'  => '',
      'wireframes_url' => '',
      'title'        => 'My Projects',
      'target_step'  => 0,
    ], $atts);

    // Bepaal de URL van de huidige pagina. Die gebruiken we als er geen URL is meegegeven.
    $current_url = '';
    if (function_exists('home_url')) {
      $scheme = is_ssl() ? 'https' : 'http';
      $current_url = home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? ''), $scheme);
    }

    // Zonder expliciete builder_url wijst de projectlink terug naar deze pagina.
    $builder_url = trim((string) $atts['builder_url']);
    if ($builder_url === '') {
      $builder_url = $current_url;
    }

    $wireframes_url = trim((string) $atts['wireframes_url']);
    if ($wireframes_url === '') {
      // Standaard gebruiken wireframes dezelfde pagina als de builder.
      $wireframes_url = $builder_url;
    }

    // Verwijder oude AISB-parameters, zodat links niet steeds langer worden.
    $builder_url = remove_query_arg(['aisb_project', 'aisb_sitemap', 'aisb_version', 'aisb_step'], $builder_url);
    $wireframes_url = remove_query_arg(['aisb_project', 'aisb_sitemap', 'aisb_version', 'aisb_step'], $wireframes_url);


    // Haal alleen de projecten op van de huidige gebruiker.
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

    // Haal alle sitemapversies in een query op. Dat is sneller dan een query per project.
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

      // Sorteer elke projectlijst opnieuw, zodat de nieuwste versie altijd eerst staat.
      foreach ($versions_by_project as $pid => $list) {
        usort($list, function($a, $b) {
          // Gebruik eerst het versienummer en daarna de aanmaakdatum als reserve.
          if ((int)$a['version'] !== (int)$b['version']) {
            return ((int)$b['version'] <=> (int)$a['version']);
          }
          return ((int)$b['createdAt'] <=> (int)$a['createdAt']);
        });
        $versions_by_project[$pid] = $list;
      }
    }

    // Bouw de projectkaarten en geef de HTML als shortcode-output terug.
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
                // Verzamel de gegevens die nodig zijn om een projectkaart te tonen.
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
                      // Maak de knop naar de nieuwste sitemapversie of naar een vaste stap.
                      $target_step = (int) $atts['target_step'];
                      if ($target_step > 0) {
                        $latest_url = add_query_arg([
                          'aisb_project' => (int) $pid,
                          'aisb_sitemap' => (int) $latest,
                          'aisb_step'    => $target_step,
                        ], $builder_url);
                      } else {
                        $latest_url = add_query_arg([
                          'aisb_project' => (int) $pid,
                          'aisb_sitemap' => (int) $latest,
                        ], $builder_url);
                      }

                      $latest_wf_url = add_query_arg([
                        'aisb_project' => (int) $pid,
                        'aisb_sitemap' => (int) $latest,
                        'aisb_step'    => 2,
                      ], $wireframes_url);
                    ?>
                    <div class="aisb-project-card-actions">
                      <?php if ($target_step > 0) : ?>
                        <a class="aisb-btn" href="<?php echo esc_url($latest_url); ?>">Select &rarr;</a>
                      <?php else : ?>
                        <a class="aisb-btn-secondary" href="<?php echo esc_url($latest_url); ?>">Open sitemap</a>
                        <a class="aisb-btn" href="<?php echo esc_url($latest_wf_url); ?>">Wireframes</a>
                      <?php endif; ?>
                      <button class="aisb-btn-secondary aisb-delete-project-btn" data-project-id="<?php echo esc_attr($pid); ?>" title="Delete Project">🗑️</button>
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
                          // Bouw per versie een leesbaar label en de juiste openingslink.
                          $v_label = 'v' . (int) $v['version'];
                          if (!empty($v['label'])) $v_label .= ' · ' . $v['label'];
                          if (!empty($v['current'])) $v_label .= ' · current';
                          $target_step_v = (int) $atts['target_step'];
                          if ($target_step_v > 0) {
                            $v_url = add_query_arg([
                              'aisb_project' => (int) $pid,
                              'aisb_sitemap' => (int) $v['id'],
                              'aisb_step'    => $target_step_v,
                            ], $builder_url);
                          } else {
                            $v_url = add_query_arg([
                              'aisb_project' => (int) $pid,
                              'aisb_sitemap' => (int) $v['id'],
                            ], $builder_url);
                          }

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
                          <?php if (!$target_step_v) : ?>
                            <a href="<?php echo esc_url($v_wf_url); ?>" title="Wireframes" class="aisb-project-version-wf">WF</a>
                          <?php endif; ?>
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

  /**
   * Rendert de hoofdshortcode [ai_sitemap_builder].
   *
   * De shortcode toont een stappenbalk en daarna precies een actieve stap:
   * 0. Mijn projecten
   * 1. Sitemap genereren
   * 2. Wireframes
  * 3. Stijlgids
  * 4. Designvoorbeeld
   */
  public function render_shortcode($atts = [], $content = null) {
    // Lees de teksten die via shortcode-attributen overschreven kunnen worden.
    $atts = shortcode_atts([
      'title' => 'Sitemap Builder',
      'placeholder' => "Describe the website you want to build.\nExample: “A modern website for a hearing clinic in Belgium. Services: hearing tests, hearing aids, tinnitus coaching. Needs online appointment booking and testimonials.”",
      'button' => 'Generate sitemap',
    ], $atts);

    // De hoofdshortcode heeft altijd de frontend-assets nodig.
    $this->enqueue_assets_for_shortcode();

    // De actieve stap komt uit de URL. Buiten bereik valt terug naar stap 1.
    $step = isset($_GET['aisb_step']) ? (int) $_GET['aisb_step'] : 1;
    if ($step < 0 || $step > 4) $step = 1;

    // Project en sitemap worden via de URL gedeeld tussen de stappen.
    $project_id = isset($_GET['aisb_project']) ? (int) $_GET['aisb_project'] : 0;
    $sitemap_id = isset($_GET['aisb_sitemap']) ? (int) $_GET['aisb_sitemap'] : 0;

    // Bouw tab-URL's en behoud daarbij de gekozen project- en sitemapcontext.
    $base_url = remove_query_arg(['aisb_step'], (function_exists('home_url') ? home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? ''), is_ssl() ? 'https' : 'http') : ''));
    if (!$base_url) {
      $base_url = remove_query_arg(['aisb_step'], add_query_arg([], $_SERVER['REQUEST_URI'] ?? ''));
    }
    $return_step = isset($_GET['aisb_return']) ? (int) $_GET['aisb_return'] : 0;

    // De projecten-tab onthoudt vanaf welke stap de gebruiker kwam.
    $tab0_args = ['aisb_step' => 0];
    if ($step > 0) $tab0_args['aisb_return'] = $step;
    $tab0_url = add_query_arg($tab0_args, remove_query_arg(['aisb_project', 'aisb_sitemap', 'aisb_return'], $base_url));
    $tab1_url = remove_query_arg(['aisb_step'], $base_url);
    $tab2_url = add_query_arg(['aisb_step' => 2], $base_url);
    $tab3_url = add_query_arg(['aisb_step' => 3], $base_url);
    $tab4_url = add_query_arg(['aisb_step' => 4], $base_url);

    ob_start(); ?>
      <div class="aisb-wrap" data-aisb data-aisb-step="<?php echo esc_attr($step); ?>">
        <div class="aisb-header">
          <h2 class="aisb-title"><?php echo esc_html($atts['title']); ?></h2>
          <p class="aisb-subtitle">
            Type a brief. You’ll get a complete sitemap with <strong>hierarchy</strong> + sections.
          </p>
        </div>

        <div class="aisb-steps">
          <a class="aisb-step-tab aisb-step-tab--projects <?php echo $step === 0 ? 'is-active' : ''; ?>" href="<?php echo esc_url($tab0_url); ?>">My Projects</a>
          <span class="aisb-step-tab-divider"></span>
          <a class="aisb-step-tab <?php echo $step === 1 ? 'is-active' : ''; ?>" href="<?php echo esc_url($tab1_url); ?>">Step 1 · Sitemap</a>
          <a class="aisb-step-tab <?php echo $step === 2 ? 'is-active' : ''; ?>" href="<?php echo esc_url($tab2_url); ?>" data-aisb-step2-tab>Step 2 · Wireframes</a>
          <a class="aisb-step-tab <?php echo $step === 3 ? 'is-active' : ''; ?>" href="<?php echo esc_url($tab3_url); ?>">Step 3 · Style Guide</a>
          <a class="aisb-step-tab <?php echo $step === 4 ? 'is-active' : ''; ?>" href="<?php echo esc_url($tab4_url); ?>">Step 4 · Design</a>
        </div>

        <?php if ($step === 0) : ?>
        <div class="aisb-step-panel" data-aisb-step-panel="0">
          <div class="aisb-card">
            <?php echo $this->render_my_projects_shortcode(['title' => 'My Projects', 'target_step' => $return_step]); ?>
          </div>
        </div><!-- /Stap 0-paneel -->
        <?php endif; ?>

        <div class="aisb-step-panel" data-aisb-step-panel="1" style="<?php echo $step === 1 ? '' : 'display:none;'; ?>">
        <div class="aisb-card aisb-input-card">
          <label class="aisb-label" for="aisb-prompt">Website brief</label>
          <textarea id="aisb-prompt" class="aisb-textarea" rows="7" maxlength="10000"
            placeholder="<?php echo esc_attr($atts['placeholder']); ?>"></textarea>
          
          <div class="aisb-field" style="margin-top: 15px; margin-bottom: 5px;">
            <label class="aisb-label" for="aisb-pdf-upload" style="display: flex; align-items: center; gap: 5px;">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
              Upload PDF brief (will be extracted as text)
            </label>
            <input type="file" id="aisb-pdf-upload" accept="application/pdf" class="aisb-file-input" style="font-size: 0.9em; padding: 5px; width: 100%; border: 1px solid #ddd; border-radius: 4px; background: #fff;">
            <div id="aisb-pdf-status" style="font-size: 0.85em; color: #666; margin-top: 6px; display: none;"></div>
          </div>

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
              <span data-aisb-counter class="aisb-counter">0 / 10000</span>
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
        </div><!-- /Stap 1-paneel -->

        <?php if ($step === 2) : ?>
        <div class="aisb-step-panel" data-aisb-step-panel="2">
          <div class="aisb-card aisb-wireframes-card" data-aisb-wireframes
               data-project-id="<?php echo esc_attr($project_id); ?>"
               data-sitemap-id="<?php echo esc_attr($sitemap_id); ?>">
            <div class="aisb-wf-head">
              <div>
                <h3 class="aisb-output-title">Wireframes</h3>
              </div>
            </div>

            <?php if (!is_user_logged_in()) : ?>
              <p>You must be logged in to use wireframes.</p>
            <?php elseif (!$project_id || !$sitemap_id) : ?>
              <div class="aisb-wf-no-project">
                <p class="aisb-wf-no-project-msg">No project selected. <a href="<?php echo esc_url($tab0_url); ?>" class="aisb-btn-link">Go to My Projects</a> to pick one.</p>
              </div>
            <?php else : ?>

              <!-- Werkbalk -->
              <div class="aisb-wf-toolbar">
                <div class="aisb-wf-toolbar-left">
                  <span class="aisb-project-switcher-name"><?php echo esc_html(get_the_title($project_id)); ?></span>
                  <a href="<?php echo esc_url($tab0_url); ?>" class="aisb-btn-secondary aisb-project-switch-btn">Switch project</a>
                </div>
                <div class="aisb-wf-toolbar-right">
                  <button class="aisb-btn generate-wireframe__all" type="button" data-aisb-wf-generate-all>Generate all</button>
                  <button class="aisb-btn" type="button" data-aisb-wf-save-all>Save all</button>
                  <a href="<?php echo esc_url(add_query_arg(['aisb_step' => 3], $base_url)); ?>" class="aisb-btn styleguide-link__button" data-aisb-wf-style>Style your wireframes</a>
                </div>
              </div>

              <div class="aisb-wf-status" data-aisb-wf-status></div>

              <!-- Werkvlak waarop alle pagina's als kaarten verschijnen -->
              <div class="aisb-wf-whiteboard" data-aisb-wf-whiteboard></div>

              <!-- Uitgeklapt paginapaneel, standaard verborgen -->
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

              <!-- Verborgen oude elementen voor bestaande JavaScript-koppelingen -->
              <div class="aisb-wf-legacy-pages" data-aisb-wf-pages></div>

              <!-- Templates die JavaScript kloont bij het opbouwen van de wireframe-ui -->
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
        </div><!-- /Stap 2-paneel -->
        <?php endif; ?>

        <?php if ($step === 3) : ?>
        <div class="aisb-step-panel" data-aisb-step-panel="3">
          <div class="aisb-card" data-styleguide
               data-styleguide-project="<?php echo esc_attr($project_id); ?>">
            <div class="aisb-sg-head">
              <div>
                <h3 class="aisb-output-title">Style Guide</h3>
              </div>
              <?php if ($project_id) : ?>
                <div class="aisb-project-switcher">
                  <span class="aisb-project-switcher-name"><?php echo esc_html(get_the_title($project_id)); ?></span>
                  <a href="<?php echo esc_url($tab0_url); ?>" class="aisb-btn-secondary aisb-project-switch-btn">Switch project</a>
                </div>
              <?php endif; ?>
            </div>

            <?php if (!is_user_logged_in()) : ?>
              <p>You must be logged in to use the Style Guide.</p>
            <?php elseif (!$project_id) : ?>
              <div class="aisb-wf-no-project">
                <p class="aisb-wf-no-project-msg">No project selected. <a href="<?php echo esc_url($tab0_url); ?>" class="aisb-btn-link">Go to My Projects</a> to pick one.</p>
              </div>
            <?php else : ?>
              <?php AISB_Style_Guide::render_style_guide_html($project_id); ?>
            <?php endif; ?>
          </div>
        </div><!-- /Stap 3-paneel -->
        <?php endif; ?>

        <?php if ($step === 4) : ?>
        <div class="aisb-step-panel" data-aisb-step-panel="4">
          <div class="aisb-card">
            <div class="aisb-sg-head">
              <div>
                <h3 class="aisb-output-title">Design</h3>
              </div>
              <?php if ($project_id) : ?>
                <div class="aisb-project-switcher">
                  <span class="aisb-project-switcher-name"><?php echo esc_html(get_the_title($project_id)); ?></span>
                  <a href="<?php echo esc_url($tab0_url); ?>" class="aisb-btn-secondary aisb-project-switch-btn">Switch project</a>
                </div>
              <?php endif; ?>
            </div>

            <?php if (!is_user_logged_in()) : ?>
              <p>You must be logged in to use the Design view.</p>
            <?php elseif (!$project_id) : ?>
              <div class="aisb-wf-no-project">
                <p class="aisb-wf-no-project-msg">No project selected. <a href="<?php echo esc_url($tab0_url); ?>" class="aisb-btn-link">Go to My Projects</a> to pick one.</p>
              </div>
            <?php else : ?>
              <?php AISB_Design::render_design_html($project_id); ?>
            <?php endif; ?>
          </div>
        </div><!-- /Stap 4-paneel -->
        <?php endif; ?>
      </div>
    <?php
    return ob_get_clean();
  }

  /**
  * Laadt bestanden op pagina's waar een frontend-shortcode staat.
   *
   * WordPress kan deze methode op elke pagina aanroepen. Daarom controleren we
   * eerst of de huidige pagina echt een AISB-shortcode bevat.
   */
  public function enqueue_assets() {
    if (!$this->current_page_has_shortcode('ai_sitemap_builder') && !$this->current_page_has_shortcode('my-projects')) return;
    $this->enqueue_assets_for_shortcode();
  }

  /**
   * Leest de plugininstellingen.
   *
   * Eerst proberen we de centrale settings-klasse. Bestaat die niet, dan lezen
   * we rechtstreeks uit de WordPress-opties.
   */
  private function get_settings(): array{
    // Gebruik de centrale settings-klasse wanneer die beschikbaar is.
    if (class_exists('AISB_Settings') && method_exists('AISB_Settings', 'get_settings')) {
        $settings = AISB_Settings::get_settings();
        return is_array($settings) ? $settings : [];
    }

    // Val terug op wp_options wanneer de settings-klasse niet geladen is.
    $settings = get_option(AISB_Plugin::OPT_KEY, []);
    return is_array($settings) ? $settings : [];
}


  /**
   * Registreert alle CSS en JavaScript voor de builder.
   *
   * De methode is idempotent: bij meerdere shortcodes op dezelfde pagina worden
  * de bestanden maar een keer toegevoegd.
   */
  private function enqueue_assets_for_shortcode() {
    if (self::$assets_enqueued) return;
    self::$assets_enqueued = true;

    $settings = $this->get_settings();

    // Laad de fonts die de frontend gebruikt.
    wp_enqueue_style(
      'aisb-google-fonts',
      'https://fonts.googleapis.com/css2?family=Archivo:ital,wdth,wght@0,62..125,100..900;1,62..125,100..900&family=Hind+Siliguri:wght@300;400;500;600;700&display=swap',
      [],
      null
    );

    // Laad de CSS-bestanden. Ze hebben onderling geen afhankelijkheden.
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

    // Laad de JavaScript-bestanden in volgorde van afhankelijkheid.
    wp_enqueue_script(
      'aisb-app-init',
      AISB_PLUGIN_URL . 'assets/js/app-init.js',
      [],
      AISB_VERSION,
      true
    );

    // Geef PHP-data door aan JavaScript voordat de andere scripts starten.
    wp_localize_script('aisb-app-init', 'AISB', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce(AISB_Plugin::NONCE_ACTION),
      'action'  => AISB_Plugin::AJAX_ACTION,
      'actionAddPage' => AISB_Plugin::AJAX_ADD_PAGE,
      'actionFillSections' => AISB_Plugin::AJAX_FILL_SECTIONS,
      'actionGetLatestSitemap' => AISB_Plugin::AJAX_GET_LATEST_SITEMAP,
      'actionGetSitemapById'   => AISB_Plugin::AJAX_GET_SITEMAP_BY_ID,
      'maxPromptChars' => 10000,
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

  /**
   * Controleert of de huidige WordPress-pagina een bepaalde shortcode bevat.
   */
  private function current_page_has_shortcode($shortcode) {
    if (!is_singular()) return false;
    global $post;
    if (!$post || empty($post->post_content)) return false;
    return has_shortcode($post->post_content, $shortcode);
  }

  /**
   * Geeft de toegestane sectietypes terug voor sitemap- en wireframegeneratie.
   */
  public function section_types():array {
    return AISB_Enforcer::section_types();
  }
}
