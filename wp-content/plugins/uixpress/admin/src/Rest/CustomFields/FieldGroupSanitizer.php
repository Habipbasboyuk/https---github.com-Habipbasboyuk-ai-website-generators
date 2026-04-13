<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class FieldGroupSanitizer
 *
 * Handles sanitization of field group data and field data
 */
class FieldGroupSanitizer
{
  /**
   * @var FieldGroupRepository
   */
  private $repository;

  /**
   * Initialize the sanitizer
   *
   * @param FieldGroupRepository $repository Repository instance
   */
  public function __construct(FieldGroupRepository $repository)
  {
    $this->repository = $repository;
  }

  /**
   * Validate ID format
   *
   * @param string $id The ID to validate
   * @return bool True if valid, false otherwise
   */
  public function is_valid_id($id)
  {
    if (empty($id) || !is_string($id)) {
      return false;
    }
    
    return preg_match('/^[a-zA-Z0-9_-]+$/', $id);
  }

  /**
   * Sanitize field group data
   *
   * @param array $data Raw field group data
   * @return array Sanitized field group data
   */
  public function sanitize_field_group_data($data)
  {
    $sanitized = [
      'id' => sanitize_text_field($data['id'] ?? ''),
      'title' => sanitize_text_field($data['title'] ?? ''),
      'description' => sanitize_textarea_field($data['description'] ?? ''),
      'active' => isset($data['active']) ? (bool)$data['active'] : true,
      'menu_order' => isset($data['menu_order']) ? absint($data['menu_order']) : 0,
      
      // Location rules
      'location' => $this->sanitize_location_rules($data['location'] ?? []),
      
      // Display settings
      'position' => sanitize_text_field($data['position'] ?? 'normal'), // normal, side, acf_after_title
      'style' => sanitize_text_field($data['style'] ?? 'default'), // default, seamless
      'label_placement' => sanitize_text_field($data['label_placement'] ?? 'top'), // top, left
      'instruction_placement' => sanitize_text_field($data['instruction_placement'] ?? 'label'), // label, field
      'hide_on_screen' => $this->sanitize_array($data['hide_on_screen'] ?? []),
      
      // Fields
      'fields' => $this->sanitize_fields($data['fields'] ?? []),
      
      // Timestamps
      'created_at' => $data['created_at'] ?? current_time('mysql'),
      'updated_at' => $data['updated_at'] ?? current_time('mysql'),
    ];

    return $sanitized;
  }

  /**
   * Sanitize location rules
   *
   * @param array $location Location rules array
   * @return array Sanitized location rules
   */
  private function sanitize_location_rules($location)
  {
    if (!is_array($location)) {
      return [];
    }

    $sanitized = [];
    foreach ($location as $group) {
      if (!is_array($group)) continue;
      
      $sanitized_group = [];
      foreach ($group as $rule) {
        if (!is_array($rule)) continue;
        
        $sanitized_group[] = [
          'param' => sanitize_text_field($rule['param'] ?? ''),
          'operator' => sanitize_text_field($rule['operator'] ?? '=='),
          'value' => sanitize_text_field($rule['value'] ?? ''),
        ];
      }
      if (!empty($sanitized_group)) {
        $sanitized[] = $sanitized_group;
      }
    }
    
    return $sanitized;
  }

  /**
   * Sanitize fields array recursively
   *
   * @param array $fields Fields array
   * @return array Sanitized fields
   */
  private function sanitize_fields($fields)
  {
    if (!is_array($fields)) {
      return [];
    }

    $sanitized = [];
    foreach ($fields as $field) {
      if (!is_array($field)) continue;
      
      $sanitized_field = [
        'id' => sanitize_text_field($field['id'] ?? $this->repository->generate_field_id()),
        'key' => sanitize_key($field['key'] ?? ''),
        'label' => sanitize_text_field($field['label'] ?? ''),
        'name' => sanitize_key($field['name'] ?? ''),
        'type' => sanitize_text_field($field['type'] ?? 'text'),
        'instructions' => sanitize_textarea_field($field['instructions'] ?? ''),
        'required' => isset($field['required']) ? (bool)$field['required'] : false,
        'default_value' => sanitize_text_field($field['default_value'] ?? ''),
        'placeholder' => sanitize_text_field($field['placeholder'] ?? ''),
        'wrapper' => [
          'width' => sanitize_text_field($field['wrapper']['width'] ?? ''),
          'class' => sanitize_text_field($field['wrapper']['class'] ?? ''),
          'id' => sanitize_text_field($field['wrapper']['id'] ?? ''),
        ],
        
        // Conditional logic
        'conditional_logic' => $this->sanitize_conditional_logic($field['conditional_logic'] ?? []),
      ];

      // Type-specific settings
      switch ($sanitized_field['type']) {
        case 'text':
        case 'textarea':
        case 'email':
        case 'url':
        case 'password':
          $sanitized_field['maxlength'] = isset($field['maxlength']) ? absint($field['maxlength']) : 0;
          $sanitized_field['prepend'] = sanitize_text_field($field['prepend'] ?? '');
          $sanitized_field['append'] = sanitize_text_field($field['append'] ?? '');
          break;
          
        case 'number':
          $sanitized_field['min'] = isset($field['min']) && $field['min'] !== '' ? floatval($field['min']) : '';
          $sanitized_field['max'] = isset($field['max']) && $field['max'] !== '' ? floatval($field['max']) : '';
          $sanitized_field['step'] = isset($field['step']) ? floatval($field['step']) : 1;
          $sanitized_field['prepend'] = sanitize_text_field($field['prepend'] ?? '');
          $sanitized_field['append'] = sanitize_text_field($field['append'] ?? '');
          break;
          
        case 'repeater':
          $sanitized_field['min'] = isset($field['min']) && $field['min'] !== '' ? absint($field['min']) : '';
          $sanitized_field['max'] = isset($field['max']) && $field['max'] !== '' ? absint($field['max']) : '';
          $sanitized_field['layout'] = sanitize_text_field($field['layout'] ?? 'table'); // table, block, row
          $sanitized_field['button_label'] = sanitize_text_field($field['button_label'] ?? __('Add Row', 'uixpress'));
          $sanitized_field['collapsed'] = sanitize_text_field($field['collapsed'] ?? '');
          $sanitized_field['sub_fields'] = $this->sanitize_fields($field['sub_fields'] ?? []);
          break;
          
        case 'select':
        case 'checkbox':
        case 'radio':
          $sanitized_field['choices'] = $this->sanitize_choices($field['choices'] ?? []);
          $sanitized_field['allow_null'] = isset($field['allow_null']) ? (bool)$field['allow_null'] : false;
          $sanitized_field['multiple'] = isset($field['multiple']) ? (bool)$field['multiple'] : false;
          $sanitized_field['ui'] = isset($field['ui']) ? (bool)$field['ui'] : false;
          break;
          
        case 'true_false':
          $sanitized_field['default_value'] = isset($field['default_value']) ? (bool)$field['default_value'] : false;
          $sanitized_field['message'] = sanitize_text_field($field['message'] ?? '');
          $sanitized_field['ui'] = isset($field['ui']) ? (bool)$field['ui'] : true;
          break;
          
        case 'wysiwyg':
          $sanitized_field['tabs'] = sanitize_text_field($field['tabs'] ?? 'all'); // all, visual, text
          $sanitized_field['toolbar'] = sanitize_text_field($field['toolbar'] ?? 'full'); // full, basic
          $sanitized_field['media_upload'] = isset($field['media_upload']) ? (bool)$field['media_upload'] : true;
          break;
          
        case 'relationship':
          $sanitized_field['relation_type'] = sanitize_text_field($field['relation_type'] ?? 'post'); // post, taxonomy
          $sanitized_field['multiple'] = isset($field['multiple']) ? (bool)$field['multiple'] : false;
          $sanitized_field['allow_null'] = isset($field['allow_null']) ? (bool)$field['allow_null'] : false;
          $sanitized_field['return_format'] = sanitize_text_field($field['return_format'] ?? 'id'); // id, object
          $sanitized_field['post_type'] = $this->sanitize_array($field['post_type'] ?? []);
          $sanitized_field['taxonomy'] = $this->sanitize_array($field['taxonomy'] ?? []);
          $sanitized_field['min'] = isset($field['min']) && $field['min'] !== '' ? absint($field['min']) : 0;
          $sanitized_field['max'] = isset($field['max']) && $field['max'] !== '' ? absint($field['max']) : 0;
          break;
          
        case 'image':
          $sanitized_field['multiple'] = isset($field['multiple']) ? (bool)$field['multiple'] : false;
          $sanitized_field['min'] = isset($field['min']) && $field['min'] !== '' ? absint($field['min']) : 0;
          $sanitized_field['max'] = isset($field['max']) && $field['max'] !== '' ? absint($field['max']) : 0;
          $sanitized_field['return_format'] = sanitize_text_field($field['return_format'] ?? 'object'); // object, url, id
          $sanitized_field['preview_size'] = sanitize_text_field($field['preview_size'] ?? 'medium'); // thumbnail, medium, large, full
          break;
          
        case 'file':
          $sanitized_field['return_format'] = sanitize_text_field($field['return_format'] ?? 'object'); // object, url, id
          $sanitized_field['mime_types'] = sanitize_text_field($field['mime_types'] ?? ''); // document, image, video, audio, or empty for all
          break;
          
        case 'date_picker':
          $sanitized_field['display_format'] = sanitize_text_field($field['display_format'] ?? 'YYYY-MM-DD');
          $sanitized_field['first_day'] = isset($field['first_day']) ? absint($field['first_day']) : 1; // 0 = Sunday, 1 = Monday
          break;
          
        case 'link':
          $sanitized_field['return_format'] = sanitize_text_field($field['return_format'] ?? 'object'); // object, url
          break;
          
        case 'color_picker':
          $sanitized_field['default_value'] = sanitize_text_field($field['default_value'] ?? '');
          $sanitized_field['enable_alpha'] = isset($field['enable_alpha']) ? (bool)$field['enable_alpha'] : false;
          $sanitized_field['return_format'] = sanitize_text_field($field['return_format'] ?? 'hex'); // hex, rgb, rgba
          break;
          
        case 'oembed':
          $sanitized_field['preview_width'] = isset($field['preview_width']) && $field['preview_width'] !== '' ? absint($field['preview_width']) : '';
          $sanitized_field['preview_height'] = isset($field['preview_height']) && $field['preview_height'] !== '' ? absint($field['preview_height']) : '';
          break;
          
        case 'range':
          $sanitized_field['default_value'] = isset($field['default_value']) && $field['default_value'] !== '' ? floatval($field['default_value']) : '';
          $sanitized_field['min'] = isset($field['min']) && $field['min'] !== '' ? floatval($field['min']) : 0;
          $sanitized_field['max'] = isset($field['max']) && $field['max'] !== '' ? floatval($field['max']) : 100;
          $sanitized_field['step'] = isset($field['step']) ? floatval($field['step']) : 1;
          $sanitized_field['prepend'] = sanitize_text_field($field['prepend'] ?? '');
          $sanitized_field['append'] = sanitize_text_field($field['append'] ?? '');
          break;
          
        case 'time_picker':
          $sanitized_field['display_format'] = sanitize_text_field($field['display_format'] ?? '12h'); // 12h, 24h
          $sanitized_field['return_format'] = sanitize_text_field($field['return_format'] ?? 'H:i:s'); // H:i:s, H:i, g:i a
          break;
          
        case 'google_map':
          $sanitized_field['center_lat'] = sanitize_text_field($field['center_lat'] ?? '51.5074');
          $sanitized_field['center_lng'] = sanitize_text_field($field['center_lng'] ?? '-0.1278');
          $sanitized_field['zoom'] = isset($field['zoom']) && $field['zoom'] !== '' ? absint($field['zoom']) : 13;
          $sanitized_field['height'] = sanitize_text_field($field['height'] ?? '400px');
          $sanitized_field['map_style'] = sanitize_text_field($field['map_style'] ?? 'streets-v12');
          break;
      }

      $sanitized[] = $sanitized_field;
    }
    
    return $sanitized;
  }

  /**
   * Sanitize conditional logic
   *
   * @param array|bool $logic Conditional logic
   * @return array|bool Sanitized conditional logic
   */
  private function sanitize_conditional_logic($logic)
  {
    if ($logic === false || empty($logic)) {
      return false;
    }

    if (!is_array($logic)) {
      return false;
    }

    $sanitized = [];
    foreach ($logic as $group) {
      if (!is_array($group)) continue;
      
      $sanitized_group = [];
      foreach ($group as $rule) {
        if (!is_array($rule)) continue;
        
        $sanitized_group[] = [
          'field' => sanitize_text_field($rule['field'] ?? ''),
          'operator' => sanitize_text_field($rule['operator'] ?? '=='),
          'value' => sanitize_text_field($rule['value'] ?? ''),
        ];
      }
      if (!empty($sanitized_group)) {
        $sanitized[] = $sanitized_group;
      }
    }
    
    return !empty($sanitized) ? $sanitized : false;
  }

  /**
   * Sanitize choices array
   *
   * @param array $choices Choices array
   * @return array Sanitized choices
   */
  private function sanitize_choices($choices)
  {
    if (!is_array($choices)) {
      return [];
    }

    $sanitized = [];
    foreach ($choices as $key => $value) {
      if (is_array($value)) {
        $sanitized[sanitize_text_field($value['value'] ?? $key)] = sanitize_text_field($value['label'] ?? $value['value'] ?? '');
      } else {
        $sanitized[sanitize_text_field($key)] = sanitize_text_field($value);
      }
    }
    
    return $sanitized;
  }

  /**
   * Sanitize array of strings
   *
   * @param array $arr Array to sanitize
   * @return array Sanitized array
   */
  private function sanitize_array($arr)
  {
    if (!is_array($arr)) {
      return [];
    }
    return array_map('sanitize_text_field', $arr);
  }

  /**
   * Regenerate field IDs recursively
   *
   * @param array $fields Array of fields
   * @return array Fields with new IDs
   */
  public function regenerate_field_ids($fields)
  {
    foreach ($fields as &$field) {
      $field['id'] = $this->repository->generate_field_id();
      
      // Handle repeater sub-fields
      if (!empty($field['sub_fields'])) {
        $field['sub_fields'] = $this->regenerate_field_ids($field['sub_fields']);
      }
    }
    return $fields;
  }
}

