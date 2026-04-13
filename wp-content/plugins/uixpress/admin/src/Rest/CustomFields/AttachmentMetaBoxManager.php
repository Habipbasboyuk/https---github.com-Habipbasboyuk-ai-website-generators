<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class AttachmentMetaBoxManager
 *
 * Handles custom field rendering and saving on attachment/media edit screens
 */
class AttachmentMetaBoxManager
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
   * Initialize the attachment meta box manager
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
   * Register attachment-related hooks for custom fields
   */
  public function register_hooks()
  {
    // Add fields to attachment edit form (attachment.php)
    add_filter('attachment_fields_to_edit', [$this, 'add_attachment_fields'], 10, 2);
    
    // Save attachment fields
    add_filter('attachment_fields_to_save', [$this, 'save_attachment_fields'], 10, 2);
    
    // Also add meta box for attachment edit page (media.php?item=X)
    add_action('add_meta_boxes_attachment', [$this, 'add_attachment_meta_box']);
  }

  /**
   * Add custom fields to attachment edit form (media library modal and edit page)
   *
   * @param array $form_fields Existing form fields
   * @param \WP_Post $post Attachment post object
   * @return array Modified form fields
   */
  public function add_attachment_fields($form_fields, $post)
  {
    $field_groups = $this->get_field_groups_for_attachment($post);
    
    if (empty($field_groups)) {
      return $form_fields;
    }

    foreach ($field_groups as $group) {
      // Get saved values
      $saved_values = [];
      if (!empty($group['fields'])) {
        foreach ($group['fields'] as $field) {
          $value = get_post_meta($post->ID, $field['name'], true);
          $saved_values[$field['name']] = $value;
        }
      }
      
      // Prepare field group data for Vue
      $vue_group_data = FieldValueSanitizer::prepare_vue_group_data($group);
      
      // Add as a form field
      $form_fields['uixpress_' . $group['id']] = [
        'label' => $group['title'],
        'input' => 'html',
        'html' => sprintf(
          '<div class="uixpress-custom-fields-app uixpress-attachment-fields" data-field-group="%s" data-saved-values="%s" data-attachment-id="%d" data-context="attachment"></div>',
          esc_attr(wp_json_encode($vue_group_data)),
          esc_attr(wp_json_encode($saved_values)),
          $post->ID
        ),
        'helps' => $group['description'] ?? '',
      ];
    }

    return $form_fields;
  }

  /**
   * Add meta box to attachment edit page
   *
   * @param \WP_Post $post Attachment post object
   */
  public function add_attachment_meta_box($post)
  {
    $field_groups = $this->get_field_groups_for_attachment($post);
    
    if (empty($field_groups)) {
      return;
    }

    foreach ($field_groups as $group) {
      add_meta_box(
        'uixpress_attachment_' . $group['id'],
        $group['title'],
        [$this, 'render_attachment_meta_box'],
        'attachment',
        'normal',
        'high',
        ['group' => $group, 'post' => $post]
      );
    }
  }

  /**
   * Render attachment meta box
   *
   * @param \WP_Post $post Attachment post object
   * @param array $args Meta box arguments
   */
  public function render_attachment_meta_box($post, $args)
  {
    $group = $args['args']['group'];
    
    // Nonce for security
    wp_nonce_field('uixpress_attachment_fields_' . $group['id'], 'uixpress_attachment_nonce_' . $group['id']);
    
    // Get saved values
    $saved_values = [];
    if (!empty($group['fields'])) {
      foreach ($group['fields'] as $field) {
        $value = get_post_meta($post->ID, $field['name'], true);
        $saved_values[$field['name']] = $value;
      }
    }
    
    // Prepare field group data for Vue
    $vue_group_data = FieldValueSanitizer::prepare_vue_group_data($group);
    
    printf(
      '<div class="uixpress-custom-fields-app uixpress-attachment-fields" data-field-group="%s" data-saved-values="%s" data-attachment-id="%d" data-context="attachment"></div>',
      esc_attr(wp_json_encode($vue_group_data)),
      esc_attr(wp_json_encode($saved_values)),
      $post->ID
    );
  }

  /**
   * Get field groups that should be displayed for an attachment
   *
   * @param \WP_Post $post Attachment post object
   * @return array Field groups
   */
  private function get_field_groups_for_attachment($post)
  {
    $field_groups = $this->repository->read();
    $matching_groups = [];
    
    if (!is_array($field_groups)) {
      return $matching_groups;
    }

    foreach ($field_groups as $group) {
      if (empty($group['active'])) {
        continue;
      }
      
      $location = $group['location'] ?? [];
      if (empty($location)) {
        continue;
      }
      
      if (LocationRuleEvaluator::should_show_for_attachment($location, $post)) {
        $matching_groups[] = $group;
      }
    }

    return $matching_groups;
  }

  /**
   * Save custom fields for an attachment (from media library modal)
   *
   * @param array $post Post data
   * @param array $attachment Attachment data
   * @return array Post data
   */
  public function save_attachment_fields($post, $attachment)
  {
    // The attachment fields come in the $attachment array
    $custom_fields = isset($attachment['uixpress_cf']) ? $attachment['uixpress_cf'] : [];
    
    if (empty($custom_fields)) {
      return $post;
    }

    $attachment_id = $post['ID'];
    
    // Load field groups
    $field_groups = $this->repository->read();
    if (!is_array($field_groups)) {
      return $post;
    }

    // Get the post object for context evaluation
    $attachment_post = get_post($attachment_id);
    if (!$attachment_post) {
      return $post;
    }

    // Process each field group
    foreach ($field_groups as $group) {
      if (empty($group['active'])) {
        continue;
      }
      
      // Check if this field group applies
      $location = $group['location'] ?? [];
      if (!empty($location) && !LocationRuleEvaluator::should_show_for_attachment($location, $attachment_post)) {
        continue;
      }
      
      // Save fields for this group
      foreach ($group['fields'] ?? [] as $field) {
        if (isset($custom_fields[$field['name']])) {
          $value = FieldValueSanitizer::sanitize($custom_fields[$field['name']], $field);
          update_post_meta($attachment_id, $field['name'], $value);
        } else {
          // Handle checkboxes (not sent when unchecked)
          if ($field['type'] === 'true_false') {
            update_post_meta($attachment_id, $field['name'], '0');
          }
        }
      }
    }

    return $post;
  }
}

