<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CommentMetaBoxManager
 *
 * Handles custom field rendering and saving on comment edit screens
 */
class CommentMetaBoxManager
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
   * Initialize the comment meta box manager
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
   * Register comment-related hooks for custom fields
   */
  public function register_hooks()
  {
    // Add meta box for comment edit screen
    add_action('add_meta_boxes_comment', [$this, 'add_comment_meta_box']);
    
    // Save comment fields
    add_action('edit_comment', [$this, 'save_comment_fields']);
  }

  /**
   * Add meta box to comment edit screen
   *
   * @param \WP_Comment $comment Comment object
   */
  public function add_comment_meta_box($comment)
  {
    $field_groups = $this->get_field_groups_for_comment($comment);
    
    if (empty($field_groups)) {
      return;
    }

    foreach ($field_groups as $group) {
      add_meta_box(
        'uixpress_comment_' . $group['id'],
        $group['title'],
        [$this, 'render_comment_fields'],
        'comment',
        'normal',
        'high',
        ['group' => $group, 'comment' => $comment]
      );
    }
  }

  /**
   * Get field groups that should be displayed for a comment
   *
   * @param \WP_Comment $comment Comment object
   * @return array Field groups
   */
  private function get_field_groups_for_comment($comment)
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
      
      if (LocationRuleEvaluator::should_show_for_comment($location, $comment)) {
        $matching_groups[] = $group;
      }
    }

    return $matching_groups;
  }

  /**
   * Render comment fields meta box
   *
   * @param \WP_Comment $comment Comment object
   * @param array $args Meta box arguments
   */
  public function render_comment_fields($comment, $args)
  {
    $group = $args['args']['group'];
    
    // Nonce for security
    wp_nonce_field('uixpress_comment_fields_' . $group['id'], 'uixpress_comment_nonce_' . $group['id']);
    
    // Get saved values for all fields
    $saved_values = [];
    if (!empty($group['fields'])) {
      foreach ($group['fields'] as $field) {
        $value = get_comment_meta($comment->comment_ID, $field['name'], true);
        $saved_values[$field['name']] = $value;
      }
    }
    
    // Prepare field group data for Vue
    $vue_group_data = FieldValueSanitizer::prepare_vue_group_data($group);
    
    printf(
      '<div class="uixpress-custom-fields-app uixpress-comment-fields" data-field-group="%s" data-saved-values="%s" data-comment-id="%d" data-context="comment"></div>',
      esc_attr(wp_json_encode($vue_group_data)),
      esc_attr(wp_json_encode($saved_values)),
      $comment->comment_ID
    );
  }

  /**
   * Save custom fields for a comment
   *
   * @param int $comment_id Comment ID
   */
  public function save_comment_fields($comment_id)
  {
    // Check permissions
    if (!current_user_can('edit_comment', $comment_id)) {
      return;
    }

    // Get custom field data
    $custom_fields = isset($_POST['uixpress_cf']) ? $_POST['uixpress_cf'] : [];
    
    if (empty($custom_fields)) {
      return;
    }

    // Load field groups
    $field_groups = $this->repository->read();
    if (!is_array($field_groups)) {
      return;
    }

    // Get comment object
    $comment = get_comment($comment_id);
    if (!$comment) {
      return;
    }

    // Process each field group
    foreach ($field_groups as $group) {
      if (empty($group['active'])) {
        continue;
      }
      
      // Check if this field group applies
      $location = $group['location'] ?? [];
      if (!empty($location) && !LocationRuleEvaluator::should_show_for_comment($location, $comment)) {
        continue;
      }
      
      $nonce_name = 'uixpress_comment_nonce_' . $group['id'];
      if (isset($_POST[$nonce_name]) && wp_verify_nonce($_POST[$nonce_name], 'uixpress_comment_fields_' . $group['id'])) {
        // Save fields for this group
        foreach ($group['fields'] ?? [] as $field) {
          if (isset($custom_fields[$field['name']])) {
            $value = FieldValueSanitizer::sanitize($custom_fields[$field['name']], $field);
            update_comment_meta($comment_id, $field['name'], $value);
          } else {
            // Handle checkboxes (not sent when unchecked)
            if ($field['type'] === 'true_false') {
              update_comment_meta($comment_id, $field['name'], '0');
            }
          }
        }
      }
    }
  }
}

