<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class FieldSaver
 *
 * Handles saving custom field values
 */
class FieldSaver
{
  /**
   * @var FieldGroupRepository
   */
  private $repository;

  /**
   * @var LocationRuleEvaluator
   */
  private $evaluator;

  /**
   * Initialize the field saver
   *
   * @param FieldGroupRepository $repository Repository instance
   * @param LocationRuleEvaluator $evaluator Evaluator instance
   */
  public function __construct(FieldGroupRepository $repository, LocationRuleEvaluator $evaluator)
  {
    $this->repository = $repository;
    $this->evaluator = $evaluator;
  }

  /**
   * Save custom fields
   * This should be called on 'save_post' hook
   *
   * @param int $post_id Post ID
   */
  public function save_custom_fields($post_id)
  {
    // Check if autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return;
    }

    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
      return;
    }

    // Check if this is a post revision
    if ( wp_is_post_revision( $post_id ) ) {
      return;
    }

    // Get custom field data
    $custom_fields = isset($_POST['uixpress_cf']) ? $_POST['uixpress_cf'] : [];
    
    if (empty($custom_fields)) {
      return;
    }

    // Load field groups to get field definitions
    $field_groups = $this->repository->read();
    if (!is_array($field_groups)) {
      return;
    }

    // Get the post object for location checks
    $post = get_post($post_id);
    if (!$post) {
      return;
    }

    // Verify nonces for each active field group
    foreach ($field_groups as $group) {
      if (empty($group['active'])) continue;
      
      // Check if this field group applies to this post
      $location = $group['location'] ?? [];
      if (!empty($location) && !LocationRuleEvaluator::should_show_for_post($location, $post)) {
        continue;
      }
      
      $nonce_name = 'uixpress_cf_nonce_' . $group['id'];
      if (isset($_POST[$nonce_name]) && wp_verify_nonce($_POST[$nonce_name], 'uixpress_custom_fields_' . $group['id'])) {
        // Save fields for this group
        foreach ($group['fields'] ?? [] as $field) {
          if (isset($custom_fields[$field['name']])) {
            $value = $custom_fields[$field['name']];
            
            // Handle relationship fields - they may be JSON encoded
            if ($field['type'] === 'relationship') {
              // Check if value is a JSON string
              if (is_string($value) && !empty($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                  $value = $decoded;
                }
              }
              $value = $this->sanitize_relationship_value($value, $field);
            } else {
              // Sanitize based on field type
              switch ($field['type']) {
                case 'email':
                  $value = sanitize_email($value);
                  break;
                case 'url':
                  $value = esc_url_raw($value);
                  break;
                case 'number':
                  $value = floatval($value);
                  break;
                case 'textarea':
                  $value = sanitize_textarea_field($value);
                  break;
                case 'wysiwyg':
                  $value = wp_kses_post($value);
                  break;
                case 'image':
                case 'file':
                  // Handle media fields - may be JSON encoded from frontend
                  if (is_string($value) && !empty($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                      $value = $decoded;
                    }
                  }
                  $value = $this->sanitize_media_value($value, $field);
                  break;
                case 'date_picker':
                  $value = sanitize_text_field($value);
                  // Validate date format (YYYY-MM-DD)
                  if ($value && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    $value = '';
                  }
                  break;
                case 'repeater':
                  $value = $this->sanitize_repeater_value($value, $field);
                  break;
                case 'select':
                  // Select can be array (multiple) or string (single)
                  if (is_array($value)) {
                    $value = array_map('sanitize_text_field', $value);
                  } else {
                    $value = sanitize_text_field($value);
                  }
                  break;
                case 'link':
                  // Handle link field - may be JSON encoded from frontend
                  if (is_string($value) && !empty($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                      $value = $decoded;
                    }
                  }
                  $value = $this->sanitize_link_value($value, $field);
                  break;
                case 'color_picker':
                  // Sanitize color value (hex, rgb, rgba)
                  $value = sanitize_text_field($value);
                  break;
                case 'oembed':
                  // Sanitize oEmbed URL
                  $value = esc_url_raw($value);
                  break;
                case 'range':
                  // Sanitize range/slider numeric value
                  $value = floatval($value);
                  break;
                case 'time_picker':
                  // Sanitize time value
                  $value = sanitize_text_field($value);
                  // Validate time format (HH:mm:ss, HH:mm, or h:mm am/pm)
                  if ($value && !preg_match('/^(\d{1,2}:\d{2}(:\d{2})?( (am|pm))?)$/i', $value)) {
                    $value = '';
                  }
                  break;
                case 'google_map':
                  // Handle Google Map field - may be JSON encoded from frontend
                  if (is_string($value) && !empty($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                      $value = $decoded;
                    }
                  }
                  $value = $this->sanitize_google_map_value($value);
                  break;
                default:
                  // Handle arrays gracefully for other field types
                  if (is_array($value)) {
                    $value = array_map('sanitize_text_field', $value);
                  } else {
                    $value = sanitize_text_field($value);
                  }
              }
            }
            
            update_post_meta($post_id, $field['name'], $value);
          } else {
            // Handle fields that might not be sent when empty
            switch ($field['type']) {
              case 'true_false':
                update_post_meta($post_id, $field['name'], '0');
                break;
              case 'relationship':
                // Clear relationship when no value is sent
                if ($field['multiple'] ?? false) {
                  update_post_meta($post_id, $field['name'], []);
                } else {
                  update_post_meta($post_id, $field['name'], null);
                }
                break;
              case 'image':
              case 'file':
              case 'link':
                // Clear media/link field when no value is sent
                if ($field['multiple'] ?? false) {
                  update_post_meta($post_id, $field['name'], []);
                } else {
                  update_post_meta($post_id, $field['name'], null);
                }
                break;
              case 'oembed':
              case 'color_picker':
              case 'range':
              case 'time_picker':
                // Clear field when no value is sent
                update_post_meta($post_id, $field['name'], '');
                break;
              case 'google_map':
                // Clear Google Map field when no value is sent
                update_post_meta($post_id, $field['name'], null);
                break;
            }
          }
        }
      }
    }
  }

  /**
   * Sanitize repeater field value
   *
   * @param array $value Repeater value
   * @param array $field Field configuration
   * @return array Sanitized value
   */
  private function sanitize_repeater_value($value, $field)
  {

    if (!is_array($value)) {
      return [];
    }

    $sanitized = [];
    foreach ($value as $row) {
      if (!is_array($row)) continue;
      
      $sanitized_row = [];
      foreach ($field['sub_fields'] ?? [] as $sub_field) {
        if (isset($row[$sub_field['name']])) {
          $sanitized_row[$sub_field['name']] = sanitize_text_field($row[$sub_field['name']]);
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
   * Stores full objects for UI display, with essential post/term data
   *
   * @param mixed $value Relationship value (array of objects or single object)
   * @param array $field Field configuration
   * @return mixed Sanitized value (array of objects or single object or null)
   */
  private function sanitize_relationship_value($value, $field)
  {
    // Handle empty values (null, empty string, empty array)
    if ($value === null || $value === '' || (is_array($value) && empty($value))) {
      return $field['multiple'] ?? false ? [] : null;
    }

    // Multiple selection - array of objects
    if (is_array($value) && isset($value[0]) && is_array($value[0])) {
      $sanitized = array_values(array_filter(array_map([$this, 'sanitize_relationship_item'], $value)));
      return $sanitized;
    }

    // Single selection - single object (check for id key)
    if (is_array($value) && isset($value['id'])) {
      $sanitized = $this->sanitize_relationship_item($value);
      return $sanitized !== null ? $sanitized : ($field['multiple'] ?? false ? [] : null);
    }

    // Fallback for unexpected formats
    return $field['multiple'] ?? false ? [] : null;
  }

  /**
   * Sanitize a single relationship item object
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
   * Sanitize media field value (image/file)
   * Handles both single media objects and arrays of media objects (for multiple selection)
   *
   * @param mixed $value Media value (array object, array of objects, or null)
   * @param array $field Field configuration
   * @return array|null Sanitized media object(s) or null if invalid
   */
  private function sanitize_media_value($value, $field = [])
  {
    // Handle empty values
    if (empty($value) || !is_array($value)) {
      return ($field['multiple'] ?? false) ? [] : null;
    }

    // Check if this is multiple mode (array of media objects)
    $isMultiple = $field['multiple'] ?? false;
    
    // Detect if value is an array of media objects (has numeric keys and first item has 'id')
    if (isset($value[0]) && is_array($value[0])) {
      // Array of media objects
      $sanitized = array_values(array_filter(array_map([$this, 'sanitize_single_media_item'], $value)));
      return $sanitized;
    }

    // Single media object (has 'id' key directly)
    if (isset($value['id'])) {
      $sanitized = $this->sanitize_single_media_item($value);
      if ($isMultiple) {
        return $sanitized ? [$sanitized] : [];
      }
      return $sanitized;
    }

    // Fallback for unexpected formats
    return $isMultiple ? [] : null;
  }

  /**
   * Sanitize a single media item object
   *
   * @param array $item The media item to sanitize
   * @return array|null Sanitized item or null if invalid
   */
  private function sanitize_single_media_item($item)
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

    // Include media_details if present (for dimensions, filesize, etc.)
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
   * @param mixed $value The value to sanitize
   * @param array $field Field configuration
   * @return mixed Sanitized value
   */
  private function sanitize_link_value($value, $field)
  {
    if (empty($value)) {
      return null;
    }

    // Handle URL-only format (string)
    if (is_string($value)) {
      return esc_url_raw($value);
    }

    // Handle object format
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
   * @param mixed $value The value to sanitize
   * @return mixed Sanitized value
   */
  private function sanitize_google_map_value($value)
  {
    if (empty($value) || !is_array($value)) {
      return null;
    }

    // Validate and sanitize coordinates
    if (!isset($value['lat']) || !isset($value['lng'])) {
      return null;
    }

    $lat = floatval($value['lat']);
    $lng = floatval($value['lng']);

    // Validate latitude and longitude ranges
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
