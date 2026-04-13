<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class MetaBoxManager
 *
 * Handles meta box registration and rendering for post edit screens
 */
class MetaBoxManager
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
   * Initialize the meta box manager
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
   * Register custom fields meta boxes
   * This should be called on 'add_meta_boxes' hook
   */
  public function register_meta_boxes()
  {
    $field_groups = $this->repository->read();
    
    if (!is_array($field_groups)) {
      return;
    }

    foreach ($field_groups as $group) {
      if (empty($group['id']) || empty($group['active'])) {
        continue;
      }

      // Get post types for this group
      $post_types = LocationRuleEvaluator::get_location_post_types($group['location'] ?? []);
      
      if (empty($post_types)) {
        continue;
      }

      foreach ($post_types as $post_type) {
        add_meta_box(
          'uixpress_' . $group['id'],
          $group['title'],
          [$this, 'render_meta_box'],
          $post_type,
          $group['position'] ?? 'normal',
          'default',
          ['group' => $group]
        );
      }
    }
  }

  /**
   * Render meta box content - Vue app container
   *
   * @param \WP_Post $post Post object
   * @param array $args Meta box arguments
   */
  public function render_meta_box($post, $args)
  {
    $group = $args['args']['group'];
    
    // Check if this field group should be shown for this specific post
    // This handles fine-grained location rules (categories, templates, etc.)
    $location = $group['location'] ?? [];
    if (!empty($location) && !LocationRuleEvaluator::should_show_for_post($location, $post)) {
      // Hide the meta box via CSS - WordPress doesn't support conditional removal at render time
      echo '<style>#uixpress_' . esc_attr($group['id']) . ' { display: none !important; }</style>';
      return;
    }
    
    // Nonce for security
    wp_nonce_field('uixpress_custom_fields_' . $group['id'], 'uixpress_cf_nonce_' . $group['id']);
    
    // Get saved values for all fields
    $saved_values = [];
    if (!empty($group['fields'])) {
      foreach ($group['fields'] as $field) {
        $value = get_post_meta($post->ID, $field['name'], true);
        $saved_values[$field['name']] = $value;
      }
    }
    
    // Prepare field group data for Vue using centralized helper
    $vue_group_data = FieldValueSanitizer::prepare_vue_group_data($group);
    
    // Output Vue app container with data attributes
    printf(
      '<div class="uixpress-custom-fields-app" data-field-group="%s" data-saved-values="%s" data-post-id="%d" data-context="post"></div>',
      esc_attr(wp_json_encode($vue_group_data)),
      esc_attr(wp_json_encode($saved_values)),
      $post->ID
    );
  }
}

