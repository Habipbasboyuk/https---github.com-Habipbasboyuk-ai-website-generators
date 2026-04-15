<?php
if (!defined('ABSPATH')) exit;

class AISB_Plugin {

	// Shared keys + actions (other classes should reference AISB_Plugin::CONST, not self::CONST)
	public const OPT_KEY = 'aisb_settings';
	public const NONCE_ACTION = 'aisb_nonce_action';

	public const AJAX_ACTION              = 'aisb_generate_sitemap';
	public const AJAX_ADD_PAGE            = 'aisb_add_page';
	public const AJAX_FILL_SECTIONS       = 'aisb_fill_sections';
	public const AJAX_CREATE_PROJECT      = 'aisb_create_project';
	public const AJAX_SAVE_SITEMAP_VERSION= 'aisb_save_sitemap_version';
	public const AJAX_GET_LATEST_SITEMAP  = 'aisb_get_latest_sitemap';
	public const AJAX_GET_SITEMAP_BY_ID   = 'aisb_get_sitemap_by_id';
	public const AJAX_LIST_PROJECTS       = 'aisb_list_projects';
	public const AJAX_LIST_SITEMAP_VERSIONS = 'aisb_list_sitemap_versions';

    const LOG_OPT_KEY = 'aisb_debug_log';
    const LOG_MAX_ENTRIES = 100;

	/** @var AISB_Settings */
	private $settings;

	/** @var AISB_Assets */
	private $assets;

	/** @var AISB_Ajax */
	private $ajax;

	/** @var AISB_Logger */
	private $logger;

	public function __construct(AISB_Settings $settings, AISB_Assets $assets, AISB_Ajax $ajax, AISB_Logger $logger) {
		$this->settings = $settings;
		$this->assets   = $assets;
		$this->ajax     = $ajax;
		$this->logger   = $logger;
	}

	/**
	 * Call from ai-sitemap-builder.php after instantiating dependencies.
	 */
	public function init(): void {
		// Frontend
		add_action('init', [$this, 'register_shortcode']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

		// Admin
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_init', [$this, 'handle_clear_log']);

		// Data model
		add_action('init', [$this, 'register_cpts']);

		// AI Wireframe admin kolommen & filters voor de wp-admin lijstpagina
		add_filter('manage_ai_wireframe_posts_columns', [$this, 'ai_wireframe_columns']);            // Extra kolommen toevoegen
		add_action('manage_ai_wireframe_posts_custom_column', [$this, 'ai_wireframe_column_content'], 10, 2); // Inhoud van de kolommen vullen
		add_filter('manage_edit-ai_wireframe_sortable_columns', [$this, 'ai_wireframe_sortable_columns']);    // Kolommen sorteerbaar maken
		add_action('restrict_manage_posts', [$this, 'ai_wireframe_filter_dropdown']);                 // Filter-dropdown bovenaan de lijst
		add_action('pre_get_posts', [$this, 'ai_wireframe_filter_query']);                            // DB-query aanpassen bij filteren/sorteren

		// Forceer Bricks om inline CSS te renderen voor ai_wireframe posts (geen cached CSS-bestand)
		add_filter('bricks/posts/force_render', function($force, $post_id) {
			if (get_post_type($post_id) === 'ai_wireframe') {
				return true;
			}
			return $force;
		}, 10, 2);

		// AJAX (logged-in)
		add_action('wp_ajax_' . self::AJAX_ACTION,               [$this, 'ajax_generate']);
		add_action('wp_ajax_' . self::AJAX_ADD_PAGE,             [$this, 'ajax_add_page']);
		add_action('wp_ajax_' . self::AJAX_FILL_SECTIONS,        [$this, 'ajax_fill_sections']);
		add_action('wp_ajax_' . self::AJAX_CREATE_PROJECT,       [$this, 'ajax_create_project']);
		add_action('wp_ajax_' . self::AJAX_SAVE_SITEMAP_VERSION, [$this, 'ajax_save_sitemap_version']);
		add_action('wp_ajax_' . self::AJAX_GET_LATEST_SITEMAP,   [$this, 'ajax_get_latest_sitemap']);
		add_action('wp_ajax_' . self::AJAX_GET_SITEMAP_BY_ID,    [$this, 'ajax_get_sitemap_by_id']);
		add_action('wp_ajax_' . self::AJAX_LIST_PROJECTS,        [$this, 'ajax_list_projects']);
		add_action('wp_ajax_' . self::AJAX_LIST_SITEMAP_VERSIONS,[$this, 'ajax_list_sitemap_versions']);

		// AJAX (public) — only if you truly want anonymous usage
		add_action('wp_ajax_nopriv_' . self::AJAX_ACTION,        [$this, 'ajax_generate']);
		add_action('wp_ajax_nopriv_' . self::AJAX_ADD_PAGE,      [$this, 'ajax_add_page']);
		add_action('wp_ajax_nopriv_' . self::AJAX_FILL_SECTIONS, [$this, 'ajax_fill_sections']);
		// NOTE: I recommend NOT exposing project CRUD/versioning endpoints to nopriv.
	}

	/* ---------------------------
	 * CPTs
	 * ------------------------- */

	public function register_cpts(): void {
		register_post_type('aisb_project', [
			'labels' => [
				'name'          => 'AI Projects',
				'singular_name' => 'AI Project',
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'supports'        => ['title', 'author', 'custom-fields'],
			'capability_type' => 'post',
			'menu_icon'       => 'dashicons-portfolio',
		]);

		register_post_type('aisb_sitemap', [
			'labels' => [
				'name'          => 'AI Sitemaps',
				'singular_name' => 'AI Sitemap',
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'supports'        => ['title', 'author', 'custom-fields'],
			'capability_type' => 'post',
			'menu_icon'       => 'dashicons-networking',
		]);

		// AI Wireframe CPT — slaat AI-gegenereerde Bricks secties op (per sectie 1 post)
		register_post_type('ai_wireframe', [
			'labels' => [
				'name'          => 'AI Wireframes',
				'singular_name' => 'AI Wireframe',
				'add_new'       => 'Add new ai_wireframe',
				'add_new_item'  => 'Add new AI Wireframe',
				'edit_item'     => 'Edit AI Wireframe',
			],
			'public'          => true,   // Niet zichtbaar op de frontend
			'show_ui'         => true,    // Wel zichtbaar in wp-admin
			'show_in_menu'    => true,
			'supports'        => ['title', 'author', 'custom-fields'],
			'capability_type' => 'post',
			'menu_icon'       => 'dashicons-layout',
		]);
	}

	/* ---------------------------
	 * AI Wireframe admin lijstpagina
	 * Voegt kolommen, filters en sortering toe
	 * ------------------------- */

	// Kolommen toevoegen na 'title': Project, Page en Source Template
	public function ai_wireframe_columns(array $columns): array {
		$new = [];
		foreach ($columns as $key => $label) {
			$new[$key] = $label;
			if ($key === 'title') {
				$new['aisb_project'] = 'Project';          // Bij welk project hoort deze wireframe
				$new['aisb_page']    = 'Page';              // Voor welke pagina (bv. 'homepage')
				$new['aisb_source']  = 'Source Template';   // Welk Bricks template als basis is gebruikt
			}
		}
		return $new;
	}

	// Inhoud van de extra kolommen per rij invullen
	public function ai_wireframe_column_content(string $column, int $post_id): void {
		// Projectnaam ophalen + klikbare link die filtert op dit project
		if ($column === 'aisb_project') {
			$pid = (int) get_post_meta($post_id, '_aisb_project_id', true);
			if ($pid) {
				$project = get_post($pid);
				// Naam + ID tonen zodat projecten met dezelfde naam te onderscheiden zijn
				$name = $project ? esc_html($project->post_title) . ' (#' . $pid . ')' : "#{$pid}";
				$filter_url = add_query_arg(['post_type' => 'ai_wireframe', 'aisb_project_filter' => $pid], admin_url('edit.php'));
				echo '<a href="' . esc_url($filter_url) . '">' . $name . '</a>';
			} else {
				echo '—';
			}
		}
		// Page slug tonen (bv. 'homepage', 'about')
		if ($column === 'aisb_page') {
			$slug = get_post_meta($post_id, '_aisb_page_slug', true);
			echo $slug ? esc_html($slug) : '—';
		}
		// Origineel Bricks template tonen dat als basis is gebruikt
		if ($column === 'aisb_source') {
			$src = (int) get_post_meta($post_id, '_aisb_source_template_id', true);
			if ($src) {
				$tpl = get_post($src);
				echo $tpl ? esc_html($tpl->post_title) . " (#{$src})" : "#{$src}";
			} else {
				echo '—';
			}
		}
	}

	// Project en Page kolommen sorteerbaar maken (klikbaar in de tabelkop)
	public function ai_wireframe_sortable_columns(array $columns): array {
		$columns['aisb_project'] = 'aisb_project';
		$columns['aisb_page']    = 'aisb_page';
		return $columns;
	}

	// Dropdown filter bovenaan de admin lijst — filter wireframes per project
	public function ai_wireframe_filter_dropdown(string $post_type): void {
		if ($post_type !== 'ai_wireframe') return;
		global $wpdb;
		// Alle unieke project IDs ophalen die aan wireframes gekoppeld zijn
		$project_ids = $wpdb->get_col(
			"SELECT DISTINCT meta_value FROM $wpdb->postmeta WHERE meta_key = '_aisb_project_id' AND meta_value != '' ORDER BY meta_value"
		);
		if (empty($project_ids)) return;

		$current = isset($_GET['aisb_project_filter']) ? (int) $_GET['aisb_project_filter'] : 0;
		echo '<select name="aisb_project_filter"><option value="">All Projects</option>';
		foreach ($project_ids as $pid) {
			$project = get_post((int) $pid);
			// Naam + ID zodat dubbele projectnamen te onderscheiden zijn
			$label = $project ? esc_html($project->post_title) . ' (#' . (int) $pid . ')' : "Project #{$pid}";
			$selected = ((int) $pid === $current) ? ' selected' : '';
			echo '<option value="' . esc_attr($pid) . '"' . $selected . '>' . $label . '</option>';
		}
		echo '</select>';
	}

	// WP_Query aanpassen wanneer de gebruiker filtert of sorteert op de admin lijst
	public function ai_wireframe_filter_query(\WP_Query $query): void {
		if (!is_admin() || !$query->is_main_query()) return;
		if (($query->get('post_type') ?? '') !== 'ai_wireframe') return;

		// Filteren op geselecteerd project uit de dropdown
		if (!empty($_GET['aisb_project_filter'])) {
			$query->set('meta_key', '_aisb_project_id');
			$query->set('meta_value', (int) $_GET['aisb_project_filter']);
		}

		// Sorteren op project (numeriek) of pagina (alfabetisch)
		$orderby = $query->get('orderby');
		if ($orderby === 'aisb_project') {
			$query->set('meta_key', '_aisb_project_id');
			$query->set('orderby', 'meta_value_num');
		}
		if ($orderby === 'aisb_page') {
			$query->set('meta_key', '_aisb_page_slug');
			$query->set('orderby', 'meta_value');
		}
	}

	/* ---------------------------
	 * Admin wiring
	 * ------------------------- */

	public function admin_menu(): void {
		$this->settings->admin_menu();
	}

	public function register_settings(): void {
		$this->settings->register_settings();
	}

	public function handle_clear_log(): void {
		$this->logger->handle_clear_log();
	}

	/* ---------------------------
	 * Shortcode + assets wiring
	 * ------------------------- */

	public function register_shortcode(): void {
		add_shortcode('ai_sitemap_builder', [$this->assets, 'render_shortcode']);
		add_shortcode('my-projects',        [$this->assets, 'render_my_projects_shortcode']);
	}

	public function enqueue_assets(): void {
		$this->assets->enqueue_assets();
	}

	/* ---------------------------
	 * AJAX wiring (delegation)
	 * ------------------------- */

	public function ajax_generate(): void {
		$this->ajax->ajax_generate();
	}

	public function ajax_add_page(): void {
		$this->ajax->ajax_add_page();
	}

	public function ajax_fill_sections(): void {
		$this->ajax->ajax_fill_sections();
	}

	public function ajax_create_project(): void {
		$this->ajax->ajax_create_project();
	}

	public function ajax_save_sitemap_version(): void {
		$this->ajax->ajax_save_sitemap_version();
	}

	public function ajax_get_latest_sitemap(): void {
		$this->ajax->ajax_get_latest_sitemap();
	}

	public function ajax_get_sitemap_by_id(): void {
		$this->ajax->ajax_get_sitemap_by_id();
	}

	public function ajax_list_projects(): void {
		$this->ajax->ajax_list_projects();
	}

	public function ajax_list_sitemap_versions(): void {
		$this->ajax->ajax_list_sitemap_versions();
	}
}
