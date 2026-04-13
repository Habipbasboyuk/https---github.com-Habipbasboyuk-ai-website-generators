<?php
namespace UiXpress\Rest\CustomFields;

use UiXpress\Rest\CustomFields\FieldGroupRepository;
use UiXpress\Rest\CustomFields\FieldValueSanitizer;
use UiXpress\Rest\CustomFields\LocationRuleEvaluator;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class OptionPageRenderer
 *
 * Handles rendering option pages with their associated field groups
 */
class OptionPageRenderer
{
  /**
   * @var FieldGroupRepository
   */
  private $field_group_repository;

  /**
   * Initialize the renderer
   */
  public function __construct()
  {
    $json_file_path = WP_CONTENT_DIR . '/uixpress-custom-fields.json';
    $this->field_group_repository = new FieldGroupRepository($json_file_path);
  }

  /**
   * Render the option page
   *
   * @param array $page Option page configuration
   */
  public function render($page)
  {
    // Get field groups that target this option page
    $field_groups = $this->get_field_groups_for_page($page['slug']);

    // Start the page wrapper
    echo '<div class="wrap uixpress-option-page-wrap">';
    
    // Page header
    echo '<h1>' . esc_html($page['title']) . '</h1>';
    
    if (!empty($page['description'])) {
      echo '<p class="description">' . esc_html($page['description']) . '</p>';
    }

    // Show message if no field groups
    if (empty($field_groups)) {
      echo '<div class="notice notice-info"><p>';
      echo esc_html__('No field groups are assigned to this option page. Create a field group and set its location to this option page.', 'uixpress');
      echo '</p></div>';
      echo '</div>';
      return;
    }

    // Start form
    echo '<form method="post" id="uixpress-option-page-form">';
    
    // Security nonce
    wp_nonce_field('uixpress_option_page_' . $page['slug'], 'uixpress_option_page_nonce');
    echo '<input type="hidden" name="uixpress_option_page_slug" value="' . esc_attr($page['slug']) . '">';
    
    // Render each field group
    foreach ($field_groups as $group) {
      $this->render_field_group($group, $page['slug']);
    }

    // Submit button
    echo '<p class="submit">';
    submit_button(__('Save Changes', 'uixpress'), 'primary', 'submit', false);
    echo '</p>';
    
    echo '</form>';
    echo '</div>';

    // Enqueue styles for the option page
    $this->enqueue_option_page_styles();
  }

  /**
   * Get field groups that should display on this option page
   *
   * @param string $page_slug Option page slug
   * @return array Field groups for this page
   */
  private function get_field_groups_for_page($page_slug)
  {
    $all_groups = $this->field_group_repository->get_active_groups();
    $matching_groups = [];

    foreach ($all_groups as $group) {
      if ($this->should_show_for_option_page($group, $page_slug)) {
        $matching_groups[] = $group;
      }
    }

    // Sort by menu_order
    usort($matching_groups, function($a, $b) {
      $order_a = isset($a['menu_order']) ? (int) $a['menu_order'] : 0;
      $order_b = isset($b['menu_order']) ? (int) $b['menu_order'] : 0;
      return $order_a - $order_b;
    });

    return $matching_groups;
  }

  /**
   * Check if a field group should be shown for the given option page
   *
   * @param array $group Field group configuration
   * @param string $page_slug Option page slug
   * @return bool
   */
  private function should_show_for_option_page($group, $page_slug)
  {
    $location = $group['location'] ?? [];

    if (empty($location)) {
      return false;
    }

    // Use the centralized LocationRuleEvaluator
    return LocationRuleEvaluator::should_show_for_option_page($location, $page_slug);
  }

  /**
   * Render a single field group
   *
   * @param array $group Field group configuration
   * @param string $page_slug Option page slug
   */
  private function render_field_group($group, $page_slug)
  {
    if (empty($group['fields'])) {
      return;
    }

    // Get saved values for all fields
    $option_key = 'uixpress_options_' . $page_slug;
    $saved_options = get_option($option_key, []);
    
    $saved_values = [];
    foreach ($group['fields'] as $field) {
      $field_name = $field['name'] ?? '';
      if (!empty($field_name)) {
        $saved_values[$field_name] = isset($saved_options[$field_name]) ? $saved_options[$field_name] : '';
      }
    }

    // Prepare field group data for Vue
    $vue_group_data = FieldValueSanitizer::prepare_vue_group_data($group);

    // Get style setting
    $style = $group['style'] ?? 'default';

    // Render field group container
    if ($style === 'seamless') {
      // Seamless style - no metabox wrapper
      printf(
        '<div class="uixpress-custom-fields-app uixpress-option-page-fields" data-field-group="%s" data-saved-values="%s" data-page-slug="%s" data-context="option"></div>',
        esc_attr(wp_json_encode($vue_group_data)),
        esc_attr(wp_json_encode($saved_values)),
        esc_attr($page_slug)
      );
    } else {
      // Default style - with metabox wrapper
      echo '<div class="postbox uixpress-option-page-postbox">';
      echo '<div class="postbox-header"><h2 class="hndle">' . esc_html($group['title']) . '</h2></div>';
      echo '<div class="inside">';
      
      if (!empty($group['description'])) {
        echo '<p class="description" style="margin-bottom: 15px;">' . esc_html($group['description']) . '</p>';
      }
      
      printf(
        '<div class="uixpress-custom-fields-app uixpress-option-page-fields" data-field-group="%s" data-saved-values="%s" data-page-slug="%s" data-context="option"></div>',
        esc_attr(wp_json_encode($vue_group_data)),
        esc_attr(wp_json_encode($saved_values)),
        esc_attr($page_slug)
      );
      
      echo '</div></div>';
    }
  }

  /**
   * Enqueue option page specific styles
   */
  private function enqueue_option_page_styles()
  {
    ?>
    <style>
      .uixpress-option-page-wrap {
        max-width: 1200px;
      }
      .uixpress-option-page-wrap .postbox {
        margin-bottom: 20px;
      }
      .uixpress-option-page-wrap .postbox-header {
        padding: 10px 15px;
        border-bottom: 1px solid #c3c4c7;
      }
      .uixpress-option-page-wrap .postbox-header h2 {
        margin: 0;
        font-size: 14px;
        font-weight: 600;
      }
      .uixpress-option-page-wrap .inside {
        padding: 15px;
      }
      .uixpress-option-page-wrap .submit {
        padding: 10px 0;
        margin-top: 20px;
        border-top: 1px solid #c3c4c7;
      }
      .uixpress-option-page-fields {
        min-height: 50px;
      }
    </style>
    <?php
  }

  /**
   * Get saved option value
   *
   * @param string $page_slug Option page slug
   * @param string $field_name Field name
   * @param mixed $default Default value
   * @return mixed
   */
  public static function get_option_value($page_slug, $field_name, $default = null)
  {
    $option_key = 'uixpress_options_' . sanitize_key($page_slug);
    $options = get_option($option_key, []);
    
    return isset($options[$field_name]) ? $options[$field_name] : $default;
  }

  /**
   * Get all option values for a page
   *
   * @param string $page_slug Option page slug
   * @return array
   */
  public static function get_all_option_values($page_slug)
  {
    $option_key = 'uixpress_options_' . sanitize_key($page_slug);
    return get_option($option_key, []);
  }
}
