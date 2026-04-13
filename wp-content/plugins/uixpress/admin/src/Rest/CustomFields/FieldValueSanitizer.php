<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class FieldValueSanitizer
 *
 * Centralized field value sanitization for all custom field contexts
 */
class FieldValueSanitizer
{
  /**
   * Enable debug logging (set to true to enable)
   */
  private static $debug_enabled = false;

  /**
   * Debug log file path
   */
  private static $debug_log_path = __DIR__ . '/debug.log';

  /**
   * Write debug log entry
   *
   * @param string $message Log message
   * @param mixed $data Optional data to log
   */
  private static function debug_log($message, $data = null)
  {
    if (!self::$debug_enabled) {
      return;
    }

    $entry = [
      'timestamp' => date('Y-m-d H:i:s'),
      'message' => $message,
    ];

    if ($data !== null) {
      $entry['data'] = $data;
    }

    file_put_contents(
      self::$debug_log_path,
      json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
      FILE_APPEND
    );
  }

  /**
   * Sanitize a field value based on its type
   *
   * @param mixed $value The value to sanitize
   * @param array $field The field configuration
   * @return mixed Sanitized value
   */
  public static function sanitize($value, $field)
  {
    $type = $field['type'] ?? 'text';

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
        return self::sanitize_repeater($value, $field);
        
      case 'group':
        return self::sanitize_group($value, $field);
        
      case 'true_false':
      case 'checkbox':
        return $value ? '1' : '0';
        
      case 'select':
      case 'radio':
      case 'button_group':
        return sanitize_text_field($value);
        
      case 'image':
      case 'file':
      case 'gallery':
        return self::sanitize_media($value);
        
      case 'post_object':
      case 'page_link':
        return self::sanitize_post_reference($value);
        
      case 'relationship':
        return self::sanitize_relationship($value, $field);
        
      case 'taxonomy':
        return self::sanitize_taxonomy_reference($value);
        
      case 'user':
        return self::sanitize_user_reference($value);
        
      case 'date_picker':
      case 'date_time_picker':
      case 'time_picker':
        return sanitize_text_field($value);
        
      case 'color_picker':
        return sanitize_hex_color($value) ?: sanitize_text_field($value);
        
      case 'google_map':
      case 'oembed':
      case 'link':
        return self::sanitize_complex_value($value);
        
      case 'text':
      case 'password':
      default:
        return sanitize_text_field($value);
    }
  }

  /**
   * Sanitize repeater field value
   *
   * @param mixed $value Repeater value
   * @param array $field Field configuration
   * @return array Sanitized value
   */
  public static function sanitize_repeater($value, $field)
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
          $sanitized_row[$field_name] = self::sanitize($row[$field_name], $sub_field);
        }
      }
      
      if (!empty($sanitized_row)) {
        $sanitized[] = $sanitized_row;
      }
    }
    
    return $sanitized;
  }

  /**
   * Sanitize group field value
   *
   * @param mixed $value Group value
   * @param array $field Field configuration
   * @return array Sanitized value
   */
  public static function sanitize_group($value, $field)
  {
    if (!is_array($value)) {
      return [];
    }

    $sanitized = [];
    $sub_fields = $field['sub_fields'] ?? [];

    foreach ($sub_fields as $sub_field) {
      $field_name = $sub_field['name'] ?? '';
      if ($field_name && isset($value[$field_name])) {
        $sanitized[$field_name] = self::sanitize($value[$field_name], $sub_field);
      }
    }
    
    return $sanitized;
  }

  /**
   * Sanitize media field value (image, file, gallery)
   * Handles JSON-encoded objects from frontend (similar to relationship fields)
   *
   * @param mixed $value Media value (JSON string, object, array of objects, ID, or array of IDs)
   * @return mixed Sanitized value
   */
  public static function sanitize_media($value)
  {
    self::debug_log('sanitize_media: input', [
      'type' => gettype($value),
      'is_string' => is_string($value),
      'value' => is_string($value) ? substr($value, 0, 500) : $value,
    ]);

    // Handle JSON-encoded values from hidden inputs
    // WordPress adds slashes to POST data (magic quotes), so we need to remove them first
    if (is_string($value) && !empty($value)) {
      $unslashed = wp_unslash($value);
      $decoded = json_decode($unslashed, true);
      
      self::debug_log('sanitize_media: JSON decode attempt', [
        'unslashed' => substr($unslashed, 0, 500),
        'json_error' => json_last_error(),
        'json_error_msg' => json_last_error_msg(),
        'decoded_type' => gettype($decoded),
      ]);
      
      if (json_last_error() === JSON_ERROR_NONE) {
        $value = $decoded;
      }
    }

    // Handle empty values
    if (empty($value)) {
      self::debug_log('sanitize_media: empty value, returning null');
      return null;
    }

    // Handle array of media objects (multiple selection)
    if (is_array($value) && isset($value[0]) && is_array($value[0])) {
      self::debug_log('sanitize_media: array of objects detected');
      $sanitized = [];
      foreach ($value as $item) {
        $sanitized_item = self::sanitize_media_item($item);
        if ($sanitized_item !== null) {
          $sanitized[] = $sanitized_item;
        }
      }
      self::debug_log('sanitize_media: sanitized array result', $sanitized);
      return array_values($sanitized);
    }

    // Handle single media object (has 'id' key)
    if (is_array($value) && isset($value['id'])) {
      self::debug_log('sanitize_media: single object detected');
      $sanitized = self::sanitize_media_item($value);
      self::debug_log('sanitize_media: sanitized single result', $sanitized);
      return $sanitized;
    }

    // Handle simple array of IDs (legacy support)
    if (is_array($value)) {
      self::debug_log('sanitize_media: array of IDs (legacy)');
      return array_map('absint', array_filter($value));
    }

    // Handle single ID
    self::debug_log('sanitize_media: single ID', absint($value));
    return absint($value);
  }

  /**
   * Sanitize a single media item object
   *
   * @param array $item The media item to sanitize
   * @return array|null Sanitized item or null if invalid
   */
  private static function sanitize_media_item($item)
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

    // Include media_details if present
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
   * Sanitize post reference field value
   *
   * @param mixed $value Post ID or array of post IDs
   * @return mixed Sanitized value
   */
  public static function sanitize_post_reference($value)
  {
    if (is_array($value)) {
      return array_map('absint', array_filter($value));
    }
    return absint($value);
  }

  /**
   * Sanitize relationship field value
   * Handles JSON-encoded values and full objects with display data
   *
   * @param mixed $value Relationship value (JSON string, array of objects, or single object)
   * @param array $field Field configuration
   * @return mixed Sanitized value (array of objects or single object or null)
   */
  public static function sanitize_relationship($value, $field)
  {
    // Handle JSON-encoded values from hidden inputs
    // WordPress adds slashes to POST data (magic quotes), so we need to remove them first
    if (is_string($value) && !empty($value)) {
      $unslashed = wp_unslash($value);
      $decoded = json_decode($unslashed, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        $value = $decoded;
      }
    }

    // Handle empty values
    if ($value === null || $value === '' || (is_array($value) && empty($value))) {
      return $field['multiple'] ?? false ? [] : null;
    }

    // Multiple selection - array of objects
    if (is_array($value) && isset($value[0]) && is_array($value[0])) {
      $sanitized = [];
      foreach ($value as $item) {
        $sanitized_item = self::sanitize_relationship_item($item);
        if ($sanitized_item !== null) {
          $sanitized[] = $sanitized_item;
        }
      }
      return array_values($sanitized);
    }

    // Single selection - single object (check for id key)
    if (is_array($value) && isset($value['id'])) {
      $sanitized = self::sanitize_relationship_item($value);
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
  private static function sanitize_relationship_item($item)
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
   * Sanitize taxonomy reference field value
   *
   * @param mixed $value Term ID or array of term IDs
   * @return mixed Sanitized value
   */
  public static function sanitize_taxonomy_reference($value)
  {
    if (is_array($value)) {
      return array_map('absint', array_filter($value));
    }
    return absint($value);
  }

  /**
   * Sanitize user reference field value
   *
   * @param mixed $value User ID or array of user IDs
   * @return mixed Sanitized value
   */
  public static function sanitize_user_reference($value)
  {
    if (is_array($value)) {
      return array_map('absint', array_filter($value));
    }
    return absint($value);
  }

  /**
   * Sanitize complex structured values (maps, oembeds, links)
   *
   * @param mixed $value Complex value
   * @return mixed Sanitized value
   */
  public static function sanitize_complex_value($value)
  {
    if (is_array($value)) {
      return array_map(function($v) {
        if (is_array($v)) {
          return self::sanitize_complex_value($v);
        }
        return is_numeric($v) ? floatval($v) : sanitize_text_field($v);
      }, $value);
    }
    
    if (filter_var($value, FILTER_VALIDATE_URL)) {
      return esc_url_raw($value);
    }
    
    return sanitize_text_field($value);
  }

  /**
   * Prepare field group data for Vue rendering
   *
   * @param array $group Field group configuration
   * @return array Vue-ready group data
   */
  public static function prepare_vue_group_data($group)
  {
    return [
      'id' => $group['id'] ?? '',
      'title' => $group['title'] ?? '',
      'description' => $group['description'] ?? '',
      'fields' => $group['fields'] ?? [],
      'style' => $group['style'] ?? 'default',
      'label_placement' => $group['label_placement'] ?? 'top',
      'instruction_placement' => $group['instruction_placement'] ?? 'label',
    ];
  }
}

