<?php
/**
 * Plugin Name: Agency Manager
 * Description: Professional project & task management plugin for WordPress agencies (Phase 1 v0.1).
 * Version: 0.1.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Agency Manager
 * License: GPLv2 or later
 * Text Domain: agency-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

// Constants.
define('AM_VERSION', '0.1.1');
define('AM_PLUGIN_FILE', __FILE__);
define('AM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once AM_PLUGIN_DIR . 'includes/bootstrap.php';

register_activation_hook(__FILE__, ['AM\\Infrastructure\\DB\\Migrator', 'activate']);
register_deactivation_hook(__FILE__, ['AM\\Infrastructure\\DB\\Migrator', 'deactivate']);

// Boot.
add_action('plugins_loaded', function () {
    AM\Bootstrap::init();
});
