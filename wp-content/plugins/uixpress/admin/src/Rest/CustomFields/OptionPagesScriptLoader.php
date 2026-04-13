<?php
namespace UiXpress\Rest\CustomFields;

use UiXpress\Rest\CustomFields\CustomFieldsScriptLoader;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class OptionPagesScriptLoader
 *
 * Handles loading scripts and styles for option pages
 * Reuses the custom fields meta box scripts for field rendering
 */
class OptionPagesScriptLoader
{
  /**
   * @var bool Track if localized data has been output
   */
  private static $localized = false;

  /**
   * Load all assets needed for option pages
   *
   * @param array $page Option page data
   * @return bool Whether assets were loaded
   */
  public static function load_assets($page)
  {
    // Load the custom fields meta box assets (Vue field components)
    CustomFieldsScriptLoader::load_assets();

    // Output option page specific data if not already done
    if (!self::$localized) {
      self::output_localized_data($page);
      self::$localized = true;
    }

    // Enqueue WordPress media library
    wp_enqueue_media();

    return true;
  }

  /**
   * Output localized data for option page
   *
   * @param array $page Option page data
   */
  private static function output_localized_data($page)
  {
    $data = [
      'pageSlug' => $page['slug'] ?? '',
      'pageTitle' => $page['title'] ?? '',
      'nonce' => wp_create_nonce('uixpress_option_page_' . ($page['slug'] ?? '')),
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'restUrl' => rest_url('uixpress/v1/'),
      'restNonce' => wp_create_nonce('wp_rest'),
      'context' => 'option',
    ];

    wp_localize_script('uixpress-custom-fields-meta-box', 'uixpressOptionPage', $data);
  }

  /**
   * Print inline script with option page context
   * Called in the page footer
   *
   * @param array $page Option page data
   */
  public static function print_option_page_context($page)
  {
    ?>
    <script>
    if (typeof window.uixpressOptionPageContext === 'undefined') {
      window.uixpressOptionPageContext = {
        pageSlug: <?php echo wp_json_encode($page['slug'] ?? ''); ?>,
        pageTitle: <?php echo wp_json_encode($page['title'] ?? ''); ?>,
        context: 'option',
        nonce: <?php echo wp_json_encode(wp_create_nonce('uixpress_option_page_' . ($page['slug'] ?? ''))); ?>
      };
    }
    </script>
    <?php
  }

  /**
   * Reset localized state (useful for testing)
   */
  public static function reset()
  {
    self::$localized = false;
  }
}
