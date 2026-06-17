<?php
/**
 * Plugin Name: AI Sitemap Builder (Shortcode)
 * Description: Shortcode that generates a sitemap + navigation hierarchy + page sections from a prompt using the OpenAI API. Includes a draggable/zoomable canvas with linked connectors and inline add-child forms. Sections are editable (title, description, type).
 * Version: 1.7.6
 * Author: Archer Websites
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

/**
 * Hoofdbestand van de plugin.
 *
 * Dit bestand definieert de plugin-constanten, laadt alle klassen en maakt de
 * service-objecten aan zodra WordPress alle plugins heeft geladen.
 */
define('AISB_VERSION', '1.7.9');
define('AISB_PLUGIN_FILE', __FILE__);
define('AISB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AISB_PLUGIN_URL', plugin_dir_url(__FILE__));

// Kernklassen: instellingen, logging, AI-call, installatie en publicatie.
require_once __DIR__ . '/includes/core/class-aisb-plugin.php';
require_once __DIR__ . '/includes/core/class-aisb-settings.php';
require_once __DIR__ . '/includes/core/class-aisb-instawp.php';
require_once __DIR__ . '/includes/core/class-aisb-openai.php';
require_once __DIR__ . '/includes/core/class-aisb-logger.php';
require_once __DIR__ . '/includes/core/class-aisb-installer.php';

// Stap 1: sitemapstructuur genereren en tonen.
require_once __DIR__ . '/includes/step1-sitemap/class-aisb-ajax.php';
require_once __DIR__ . '/includes/step1-sitemap/class-aisb-prompts.php';
require_once __DIR__ . '/includes/step1-sitemap/class-aisb-enforcer.php';
require_once __DIR__ . '/includes/step1-sitemap/class-aisb-assets.php';

// Stap 2: wireframes opbouwen uit Bricks/Brixies templates.
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-template-analyzer.php';
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-template-library.php';
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-wireframe-compiler.php';
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-wireframes-bricks.php';
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-wireframes-ai.php';
require_once __DIR__ . '/includes/step2-wireframes/class-aisb-wireframes.php';

// Stap 3: stijlgids genereren en beheren.
require_once __DIR__ . '/includes/step3-style-guide/class-aisb-sg-ajax.php';
require_once __DIR__ . '/includes/step3-style-guide/class-aisb-sg-images.php';
require_once __DIR__ . '/includes/step3-style-guide/class-aisb-sg-wireframes.php';
require_once __DIR__ . '/includes/step3-style-guide/class-aisb-style-guide.php';

// Stap 4: designpreview, secties vervangen en Figma-export.
require_once __DIR__ . '/includes/step4-design/class-aisb-design-ajax.php';
require_once __DIR__ . '/includes/step4-design/class-aisb-design-figma.php';
require_once __DIR__ . '/includes/step4-design/class-aisb-design.php';

register_activation_hook(__FILE__, ['AISB_Installer', 'activate']);

add_action('plugins_loaded', function () {
  // Maak alle service-objecten aan en geef dependencies expliciet door.
  $logger   = new AISB_Logger();
  $settings = new AISB_Settings();
  $prompts  = new AISB_Prompts();
  $enforcer = new AISB_Enforcer($prompts);
  $openai   = new AISB_OpenAI($settings, $logger, $prompts);
  $assets   = new AISB_Assets($settings, $prompts);
  $ajax     = new AISB_Ajax($settings, $logger, $openai, $enforcer);

  // Stap 2: helpers voor templateanalyse, compilatie en wireframes.
  $analyzer  = new AISB_Template_Analyzer();
  $tpl_lib   = new AISB_Template_Library($analyzer);
  $compiler  = new AISB_Wireframe_Compiler($tpl_lib);
  $wireframes= new AISB_Wireframes($tpl_lib, $compiler);
  $instawp   = new AISB_InstaWP($compiler);

  // Stap 3: stijlgidsmodule.
  $style_guide = new AISB_Style_Guide();

  // Stap 4: designmodule.
  $design = new AISB_Design();

  // De hoofdplugin registreert de centrale WordPress-hooks.
  $plugin   = new AISB_Plugin($settings, $assets, $ajax, $logger);

  $plugin->init();
  $wireframes->init();
  $instawp->init();
  $style_guide->init();
  $design->init();
});
