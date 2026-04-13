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
