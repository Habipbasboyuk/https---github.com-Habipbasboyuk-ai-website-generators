<?php
namespace AM;

use AM\Infrastructure\Capabilities\Roles;
use AM\Infrastructure\Http\Rest_Router;
use AM\Support\CPT;
use AM\Support\Taxonomies;
use AM\Support\Metaboxes;
use AM\Public_Front\Shortcodes;

if (!defined('ABSPATH')) { exit; }

final class Bootstrap {
    public static function init(): void {
        // Register CPTs & taxonomies.
        add_action('init', [CPT::class, 'register']);
        add_action('init', [Taxonomies::class, 'register']);

        // REST routes (internal API).
        add_action('rest_api_init', [Rest_Router::class, 'register_routes']);

        // Shortcodes for portal.
        add_action('init', [Shortcodes::class, 'register']);

        // Metaboxes for phase-1 data entry (no SPA yet).
        add_action('init', [Metaboxes::class, 'register']);

        // Enqueue minimal frontend styles.
        add_action('wp_enqueue_scripts', [Shortcodes::class, 'enqueue_assets']);

        // Admin menu placeholder (React SPA comes later).
        add_action('admin_menu', function () {
            add_menu_page(
                __('Agency Manager', 'agency-manager'),
                __('Agency Manager', 'agency-manager'),
                'am_manage_projects',
                'agency-manager',
                function () {
                    echo '<div class="wrap"><h1>Agency Manager</h1><p>v' . esc_html(AM_VERSION) . '</p><p>Admin UI scaffold is in place. Use the REST API + shortcodes for now.</p></div>';
                },
                'dashicons-clipboard',
                58
            );
        });
    }
}

// Autoloader (very small PSR-4 style for this plugin).
spl_autoload_register(function ($class) {
    if (strpos($class, 'AM\\') !== 0) return;
    $relative = str_replace('AM\\', '', $class);
    $relative = str_replace('\\', '/', $relative);
    $file = AM_PLUGIN_DIR . 'includes/' . $relative . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Ensure roles/caps exist after plugins loaded.
add_action('init', [Roles::class, 'ensure_roles']);
