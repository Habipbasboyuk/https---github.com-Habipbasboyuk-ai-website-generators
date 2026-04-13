<?php
namespace UiXpress\Rest\CustomFields;

use UiXpress\Rest\CustomFields\FieldGroupRepository;
use UiXpress\Rest\CustomFields\FieldValueSanitizer;
use UiXpress\Rest\CustomFields\LocationRuleEvaluator;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class OptionPageSaver
 *
 * Handles saving option page field values to wp_options
 */
class OptionPageSaver
{
  /**
   * @var FieldGroupRepository
   */
  private $field_group_repository;

  /**
   * Initialize the saver
   */
  public function __construct()
  {
    $json_file_path = WP_CONTENT_DIR . '/uixpress-custom-fields.json';
    $this->field_group_repository = new FieldGroupRepository($json_file_path);
  }

  /**
   * Initialize hooks
   */
  public function init()
  {
    add_action('admin_init', [$this, 'handle_save']);
  }

  /**
   * Handle option page form submission
   */
  public function handle_save()
  {
    // Check if this is our form submission
    if (!isset($_POST['uixpress_option_page_nonce']) || !isset($_POST['uixpress_option_page_slug'])) {
      return;
    }

    $page_slug = sanitize_key($_POST['uixpress_option_page_slug']);

    // Verify nonce
    if (!wp_verify_nonce($_POST['uixpress_option_page_nonce'], 'uixpress_option_page_' . $page_slug)) {
      wp_die(__('Security check failed.', 'uixpress'));
    }

    // Check permissions
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have permission to save these options.', 'uixpress'));
    }

    // Get field groups for this option page
    $field_groups = $this->get_field_groups_for_page($page_slug);

    if (empty($field_groups)) {
      return;
    }

    // Get custom field data
    $custom_fields = isset($_POST['uixpress_cf']) ? $_POST['uixpress_cf'] : [];

    // Build options array
    $option_key = 'uixpress_options_' . $page_slug;
    $existing_options = get_option($option_key, []);
    $new_options = [];

    // Process each field group
    foreach ($field_groups as $group) {
      foreach ($group['fields'] ?? [] as $field) {
        $field_name = $field['name'] ?? '';
        
        if (empty($field_name)) {
          continue;
        }

        if (isset($custom_fields[$field_name])) {
          $value = $custom_fields[$field_name];
          $new_options[$field_name] = $this->sanitize_field_value($value, $field);
        } else {
          // Handle fields that might not be sent when empty
          $new_options[$field_name] = $this->get_empty_value($field);
        }
      }
    }

    // Merge with existing options (to preserve fields from other groups)
    $merged_options = array_merge($existing_options, $new_options);

    // Save to wp_options
    update_option($option_key, $merged_options);

    // Redirect back with success message
    $redirect_url = add_query_arg([
      'page' => 'uixpress-options-' . $page_slug,
      'settings-updated' => 'true',
    ], admin_url('admin.php'));

    wp_safe_redirect($redirect_url);
    exit;
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
   * Sanitize a field value based on its type
   *
   * @param mixed $value The value to sanitize
   * @param array $field Field configuration
   * @return mixed Sanitized value
   */
  private function sanitize_field_value($value, $field)
  {
    $type = $field['type'] ?? 'text';

    // Handle JSON-encoded values from hidden inputs
    if (is_string($value) && !empty($value)) {
      $unslashed = wp_unslash($value);
      $decoded = json_decode($unslashed, true);
      if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
        $value = $decoded;
      }
    }

    switch ($type) {
      case 'email':
        return sanitize_email($value);

      case 'url':
        return esc_url_raw($value);

      case 'number':
      case 'range':
        return floatval($value);

      case 'textarea':
        return sanitize_textarea_field($value);

      case 'wysiwyg':
        return wp_kses_post($value);

      case 'repeater':
        return $this->sanitize_repeater_value($value, $field);

      case 'true_false':
      case 'checkbox':
        return $value ? '1' : '0';

      case 'select':
        if (is_array($value)) {
          return array_map('sanitize_text_field', $value);
        }
        return sanitize_text_field($value);

      case 'image':
      case 'file':
        return $this->sanitize_media_value($value, $field);

      case 'relationship':
        return $this->sanitize_relationship_value($value, $field);

      case 'link':
        return $this->sanitize_link_value($value);

      case 'color_picker':
        return sanitize_text_field($value);

      case 'oembed':
        return esc_url_raw($value);

      case 'date_picker':
        $value = sanitize_text_field($value);
        if ($value && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
          $value = '';
        }
        return $value;

      case 'time_picker':
        $value = sanitize_text_field($value);
        if ($value && !preg_match('/^(\d{1,2}:\d{2}(:\d{2})?( (am|pm))?)$/i', $value)) {
          $value = '';
        }
        return $value;

      case 'google_map':
        return $this->sanitize_google_map_value($value);

      default:
        if (is_array($value)) {
          return array_map('sanitize_text_field', $value);
        }
        return sanitize_text_field($value);
    }
  }

  /**
   * Get empty value for a field type
   *
   * @param array $field Field configuration
   * @return mixed Empty value appropriate for the field type
   */
  private function get_empty_value($field)
  {
    $type = $field['type'] ?? 'text';

    switch ($type) {
      case 'true_false':
        return '0';

      case 'repeater':
      case 'relationship':
        return [];

      case 'image':
      case 'file':
      case 'link':
      case 'google_map':
        return ($field['multiple'] ?? false) ? [] : null;

      default:
        return '';
    }
  }

  /**
   * Sanitize repeater field value
   *
   * @param mixed $value Repeater value
   * @param array $field Field configuration
   * @return array Sanitized value
   */
  private function sanitize_repeater_value($value, $field)
  {
    if (!is_array($value)) {
      return [];
    }

    $sanitized = [];
    $sub_fields = $field['sub_fields'] ?? [];

    foreach ($value as $row) {
      if (!is_array($row)) {
        continue;
      }

      $sanitized_row = [];
      foreach ($sub_fields as $sub_field) {
        $field_name = $sub_field['name'] ?? '';
        if ($field_name && isset($row[$field_name])) {
          $sanitized_row[$field_name] = $this->sanitize_field_value($row[$field_name], $sub_field);
        }
      }

      if (!empty($sanitized_row)) {
        $sanitized[] = $sanitized_row;
      }
    }

    return $sanitized;
  }

  /**
   * Sanitize relationship field value
   *
   * @param mixed $value Relationship value
   * @param array $field Field configuration
   * @return mixed Sanitized value
   */
  private function sanitize_relationship_value($value, $field)
  {
    if (empty($value)) {
      return ($field['multiple'] ?? false) ? [] : null;
    }

    // Multiple selection - array of objects
    if (is_array($value) && isset($value[0]) && is_array($value[0])) {
      return array_values(array_filter(array_map([$this, 'sanitize_relationship_item'], $value)));
    }

    // Single selection - single object
    if (is_array($value) && isset($value['id'])) {
      $sanitized = $this->sanitize_relationship_item($value);
      return $sanitized !== null ? $sanitized : (($field['multiple'] ?? false) ? [] : null);
    }

    return ($field['multiple'] ?? false) ? [] : null;
  }

  /**
   * Sanitize a single relationship item
   *
   * @param array $item The item to sanitize
   * @return array|null Sanitized item or null if invalid
   */
  private function sanitize_relationship_item($item)
  {
    if (!is_array($item) || empty($item['id'])) {
      return null;
    }

    return [
      'id' => absint($item['id']),
      'title' => isset($item['title']) ? sanitize_text_field($item['title']) : '',
      'type' => isset($item['type']) ? sanitize_key($item['type']) : '',
      'type_label' => isset($item['type_label']) ? sanitize_text_field($item['type_label']) : '',
      'status' => isset($item['status']) ? sanitize_key($item['status']) : 'publish',
      'status_label' => isset($item['status_label']) ? sanitize_text_field($item['status_label']) : '',
      'thumbnail' => isset($item['thumbnail']) ? esc_url_raw($item['thumbnail']) : null,
    ];
  }

  /**
   * Sanitize media field value
   *
   * @param mixed $value Media value
   * @param array $field Field configuration
   * @return mixed Sanitized value
   */
  private function sanitize_media_value($value, $field = [])
  {
    if (empty($value) || !is_array($value)) {
      return ($field['multiple'] ?? false) ? [] : null;
    }

    // Array of media objects
    if (isset($value[0]) && is_array($value[0])) {
      return array_values(array_filter(array_map([$this, 'sanitize_media_item'], $value)));
    }

    // Single media object
    if (isset($value['id'])) {
      $sanitized = $this->sanitize_media_item($value);
      if ($field['multiple'] ?? false) {
        return $sanitized ? [$sanitized] : [];
      }
      return $sanitized;
    }

    return ($field['multiple'] ?? false) ? [] : null;
  }

  /**
   * Sanitize a single media item
   *
   * @param array $item The media item to sanitize
   * @return array|null Sanitized item or null if invalid
   */
  private function sanitize_media_item($item)
  {
    if (!is_array($item) || empty($item['id'])) {
      return null;
    }

    $sanitized = [
      'id' => absint($item['id']),
      'title' => isset($item['title']) ? sanitize_text_field($item['title']) : '',
      'source_url' => isset($item['source_url']) ? esc_url_raw($item['source_url']) : '',
      'mime_type' => isset($item['mime_type']) ? sanitize_mime_type($item['mime_type']) : '',
      'alt_text' => isset($item['alt_text']) ? sanitize_text_field($item['alt_text']) : '',
    ];

    if (isset($item['media_details']) && is_array($item['media_details'])) {
      $sanitized['media_details'] = [
        'width' => isset($item['media_details']['width']) ? absint($item['media_details']['width']) : null,
        'height' => isset($item['media_details']['height']) ? absint($item['media_details']['height']) : null,
        'filesize' => isset($item['media_details']['filesize']) ? absint($item['media_details']['filesize']) : null,
      ];
    }

    return $sanitized;
  }

  /**
   * Sanitize link value
   *
   * @param mixed $value Link value
   * @return mixed Sanitized value
   */
  private function sanitize_link_value($value)
  {
    if (empty($value)) {
      return null;
    }

    if (is_string($value)) {
      return esc_url_raw($value);
    }

    if (is_array($value)) {
      return [
        'url' => isset($value['url']) ? esc_url_raw($value['url']) : '',
        'title' => isset($value['title']) ? sanitize_text_field($value['title']) : '',
        'target' => isset($value['target']) ? sanitize_text_field($value['target']) : '_self',
      ];
    }

    return null;
  }

  /**
   * Sanitize Google Map value
   *
   * @param mixed $value Google Map value
   * @return mixed Sanitized value
   */
  private function sanitize_google_map_value($value)
  {
    if (empty($value) || !is_array($value)) {
      return null;
    }

    if (!isset($value['lat']) || !isset($value['lng'])) {
      return null;
    }

    $lat = floatval($value['lat']);
    $lng = floatval($value['lng']);

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
      return null;
    }

    return [
      'address' => isset($value['address']) ? sanitize_text_field($value['address']) : '',
      'lat' => $lat,
      'lng' => $lng,
      'zoom' => isset($value['zoom']) ? absint($value['zoom']) : 13,
    ];
  }
}
