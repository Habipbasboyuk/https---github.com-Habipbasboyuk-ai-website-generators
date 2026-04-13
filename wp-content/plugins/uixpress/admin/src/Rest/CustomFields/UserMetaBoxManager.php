<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class UserMetaBoxManager
 *
 * Handles custom field rendering and saving on user profile screens
 */
class UserMetaBoxManager
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
   * Initialize the user meta box manager
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
   * Register user-related hooks for custom fields
   */
  public function register_hooks()
  {
    // Show fields on user profile and edit screens
    add_action('show_user_profile', [$this, 'render_user_fields'], 10, 1);
    add_action('edit_user_profile', [$this, 'render_user_fields'], 10, 1);
    
    // Show fields on add new user screen
    add_action('user_new_form', [$this, 'render_add_user_fields'], 10, 1);
    
    // Save user fields
    add_action('personal_options_update', [$this, 'save_user_fields']);
    add_action('edit_user_profile_update', [$this, 'save_user_fields']);
    add_action('user_register', [$this, 'save_user_fields']);
  }

  /**
   * Render custom fields on user profile/edit screens
   *
   * @param \WP_User $user User object
   */
  public function render_user_fields($user)
  {
    $context = 'edit'; // profile or edit_user
    $field_groups = $this->get_field_groups_for_user($user, $context);
    
    if (empty($field_groups)) {
      return;
    }

    foreach ($field_groups as $group) {
      $this->render_fields($group, $user, $context);
    }
  }

  /**
   * Render custom fields on add new user screen
   *
   * @param string $operation The type of operation (add-new-user or add-existing-user)
   */
  public function render_add_user_fields($operation)
  {
    if ($operation !== 'add-new-user') {
      return;
    }

    $context = 'add';
    $field_groups = $this->get_field_groups_for_user(null, $context);
    
    if (empty($field_groups)) {
      return;
    }

    foreach ($field_groups as $group) {
      $this->render_fields($group, null, $context);
    }
  }

  /**
   * Get field groups that should be displayed for a user context
   *
   * @param \WP_User|null $user User object (null for add new)
   * @param string $context 'add' or 'edit'
   * @return array Field groups
   */
  private function get_field_groups_for_user($user, $context)
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
      
      if (LocationRuleEvaluator::should_show_for_user($location, $user, $context)) {
        $matching_groups[] = $group;
      }
    }

    return $matching_groups;
  }

  /**
   * Render field group fields for a user
   *
   * @param array $group Field group
   * @param \WP_User|null $user User object (null for add new)
   * @param string $context 'add' or 'edit'
   */
  private function render_fields($group, $user, $context)
  {
    // Nonce for security
    wp_nonce_field('uixpress_user_fields_' . $group['id'], 'uixpress_user_nonce_' . $group['id']);
    
    // Get saved values for all fields
    $saved_values = [];
    if ($user && !empty($group['fields'])) {
      foreach ($group['fields'] as $field) {
        $value = get_user_meta($user->ID, $field['name'], true);
        $saved_values[$field['name']] = $value;
      }
    }
    
    // Prepare field group data for Vue
    $vue_group_data = FieldValueSanitizer::prepare_vue_group_data($group);
    $user_id = $user ? $user->ID : 0;
    
    // Output section wrapper
    echo '<h2>' . esc_html($group['title']) . '</h2>';
    if (!empty($group['description'])) {
      echo '<p class="description">' . esc_html($group['description']) . '</p>';
    }
    
    echo '<table class="form-table" role="presentation">';
    echo '<tbody>';
    echo '<tr class="uixpress-user-fields-row">';
    echo '<th scope="row">' . esc_html__('Custom Fields', 'uixpress') . '</th>';
    echo '<td>';
    printf(
      '<div class="uixpress-custom-fields-app uixpress-user-fields" data-field-group="%s" data-saved-values="%s" data-user-id="%d" data-context="user"></div>',
      esc_attr(wp_json_encode($vue_group_data)),
      esc_attr(wp_json_encode($saved_values)),
      $user_id
    );
    echo '</td>';
    echo '</tr>';
    echo '</tbody>';
    echo '</table>';
  }

  /**
   * Save custom fields for a user
   *
   * @param int $user_id User ID
   */
  public function save_user_fields($user_id)
  {
    // Check permissions
    if (!current_user_can('edit_user', $user_id)) {
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

    // Get user object for context evaluation
    $user = get_user_by('ID', $user_id);
    $context = $user ? 'edit' : 'add';

    // Process each field group
    foreach ($field_groups as $group) {
      if (empty($group['active'])) {
        continue;
      }
      
      // Check if this field group applies
      $location = $group['location'] ?? [];
      if (!empty($location) && !LocationRuleEvaluator::should_show_for_user($location, $user, $context)) {
        continue;
      }
      
      $nonce_name = 'uixpress_user_nonce_' . $group['id'];
      if (isset($_POST[$nonce_name]) && wp_verify_nonce($_POST[$nonce_name], 'uixpress_user_fields_' . $group['id'])) {
        // Save fields for this group
        foreach ($group['fields'] ?? [] as $field) {
          if (isset($custom_fields[$field['name']])) {
            $value = FieldValueSanitizer::sanitize($custom_fields[$field['name']], $field);
            update_user_meta($user_id, $field['name'], $value);
          } else {
            // Handle checkboxes (not sent when unchecked)
            if ($field['type'] === 'true_false') {
              update_user_meta($user_id, $field['name'], '0');
            }
          }
        }
      }
    }
  }
}

