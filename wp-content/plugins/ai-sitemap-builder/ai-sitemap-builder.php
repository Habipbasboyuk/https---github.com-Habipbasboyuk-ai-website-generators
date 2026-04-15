<?php
/**
 * Plugin Name: AI Sitemap Builder (Shortcode)
 * Description: Shortcode that generates a sitemap + navigation hierarchy + page sections from a prompt using the OpenAI API. Includes a draggable/zoomable canvas with linked connectors and inline add-child forms. Sections are editable (title, description, type).
 * Version: 1.4.1
 * Author: Archer Websites
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('AISB_VERSION', '1.5.9');
define('AISB_PLUGIN_FILE', __FILE__);
define('AISB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AISB_PLUGIN_URL', plugin_dir_url(__FILE__));

// Core
require_once __DIR__ . '/includes/core/class-aisb-plugin.php';
require_once __DIR__ . '/includes/core/class-aisb-settings.php';
require_once __DIR__ . '/includes/core/class-aisb-openai.php';
require_once __DIR__ . '/includes/core/class-aisb-logger.php';
require_once __DIR__ . '/includes/core/class-aisb-installer.php';

// Step 1 (Sitemap)
require_once __DIR__ . '/includes/step1-sitemap/class-aisb-ajax.php';
require_once __DIR__ . '/includes/step1-sitemap/class-aisb-prompts.php';
require_once __DIR__ . '/includes/step1-sitemap/class-aisb-enforcer.php';
require_once __DIR__ . '/includes/step1-sitemap/class-aisb-assets.php';

// Step 2 (Wireframes)
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-template-analyzer.php';
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-template-library.php';
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-wireframe-compiler.php';
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-wireframes-bricks.php';
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-wireframes-ai.php';
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-wireframes.php';

// Step 3 (Style Guide)
require_once __DIR__ . '/includes/step3-style-guide/class-aisb-style-guide.php';

register_activation_hook(__FILE__, ['AISB_Installer', 'activate']);

add_action('plugins_loaded', function () {
  // Create the “service” objects
  $logger   = new AISB_Logger();
  $settings = new AISB_Settings();
  $prompts  = new AISB_Prompts();
  $enforcer = new AISB_Enforcer($prompts);
  $openai   = new AISB_OpenAI($settings, $logger, $prompts);
  $assets   = new AISB_Assets($settings, $prompts);
  $ajax     = new AISB_Ajax($settings, $logger, $openai, $enforcer);

  // Step 2 (Wireframes)
  $analyzer  = new AISB_Template_Analyzer();
  $tpl_lib   = new AISB_Template_Library($analyzer);
  $compiler  = new AISB_Wireframe_Compiler($tpl_lib);
  $wireframes= new AISB_Wireframes($tpl_lib, $compiler);

  // Step 3 (Style Guide)
  $style_guide = new AISB_Style_Guide();

  // Main plugin wires everything together
  $plugin   = new AISB_Plugin($settings, $assets, $ajax, $logger);

  $plugin->init();
  $wireframes->init();
  $style_guide->init();
});
