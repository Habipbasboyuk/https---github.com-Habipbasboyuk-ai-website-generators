<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class TaxonomyMetaBoxManager
 *
 * Handles custom field rendering and saving on taxonomy term edit screens
 */
class TaxonomyMetaBoxManager
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
   * Initialize the taxonomy meta box manager
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
   * Register taxonomy term hooks for custom fields
   * This should be called during plugin initialization
   */
  public function register_taxonomy_hooks()
  {
    // Get all taxonomies that have field groups assigned
    $taxonomies = $this->get_taxonomies_with_fields();
    
    if (empty($taxonomies)) {
      return;
    }

    foreach ($taxonomies as $taxonomy) {
      // Add form fields hooks
      add_action("{$taxonomy}_add_form_fields", [$this, 'render_add_form_fields'], 10, 1);
      add_action("{$taxonomy}_edit_form_fields", [$this, 'render_edit_form_fields'], 10, 2);
      
      // Save hooks
      add_action("created_{$taxonomy}", [$this, 'save_term_fields'], 10, 2);
      add_action("edited_{$taxonomy}", [$this, 'save_term_fields'], 10, 2);
    }
    
    // Enqueue scripts on taxonomy screens
    add_action('admin_footer', [$this, 'print_taxonomy_scripts']);
  }

  /**
   * Get all taxonomies that have field groups assigned to them
   *
   * @return array List of taxonomy names
   */
  private function get_taxonomies_with_fields()
  {
    $field_groups = $this->repository->read();
    $taxonomies = [];
    
    if (!is_array($field_groups)) {
      return $taxonomies;
    }

    // Get all registered taxonomies
    $all_taxonomies = get_taxonomies(['public' => true], 'names');
    
    foreach ($field_groups as $group) {
      if (empty($group['active'])) {
        continue;
      }
      
      $location = $group['location'] ?? [];
      $group_taxonomies = LocationRuleEvaluator::get_location_taxonomies($location);
      
      $taxonomies = array_merge($taxonomies, $group_taxonomies);
    }
    
    return array_unique($taxonomies);
  }

  /**
   * Render custom fields on the add term form
   *
   * @param string $taxonomy Taxonomy name
   */
  public function render_add_form_fields($taxonomy)
  {
    $field_groups = $this->get_field_groups_for_taxonomy($taxonomy, null);
    
    if (empty($field_groups)) {
      return;
    }

    foreach ($field_groups as $group) {
      $this->render_term_fields($group, null, 'add');
    }
  }

  /**
   * Render custom fields on the edit term form
   *
   * @param \WP_Term $term Term object
   * @param string $taxonomy Taxonomy name
   */
  public function render_edit_form_fields($term, $taxonomy)
  {
    $field_groups = $this->get_field_groups_for_taxonomy($taxonomy, $term);
    
    if (empty($field_groups)) {
      return;
    }

    foreach ($field_groups as $group) {
      $this->render_term_fields($group, $term, 'edit');
    }
  }

  /**
   * Get field groups that should be displayed for a taxonomy
   *
   * @param string $taxonomy Taxonomy name
   * @param \WP_Term|null $term Term object (null for add form)
   * @return array Field groups
   */
  private function get_field_groups_for_taxonomy($taxonomy, $term)
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
      
      if (LocationRuleEvaluator::should_show_for_taxonomy($location, $taxonomy, $term)) {
        $matching_groups[] = $group;
      }
    }

    return $matching_groups;
  }

  /**
   * Render field group fields for a term
   *
   * @param array $group Field group
   * @param \WP_Term|null $term Term object
   * @param string $context 'add' or 'edit'
   */
  private function render_term_fields($group, $term, $context)
  {
    // Nonce for security
    wp_nonce_field('uixpress_taxonomy_fields_' . $group['id'], 'uixpress_tax_nonce_' . $group['id']);
    
    // Get saved values for all fields
    $saved_values = [];
    if ($term && !empty($group['fields'])) {
      foreach ($group['fields'] as $field) {
        $value = get_term_meta($term->term_id, $field['name'], true);
        $saved_values[$field['name']] = $value;
      }
    }
    
    // Prepare field group data for Vue using centralized helper
    $vue_group_data = FieldValueSanitizer::prepare_vue_group_data($group);
    
    $term_id = $term ? $term->term_id : 0;
    
    if ($context === 'edit') {
      // For edit form, wrap in table rows
      echo '<tr class="form-field">';
      echo '<th scope="row">';
      echo '<label>' . esc_html($group['title']) . '</label>';
      echo '</th>';
      echo '<td>';
      printf(
        '<div class="uixpress-custom-fields-app uixpress-taxonomy-fields" data-field-group="%s" data-saved-values="%s" data-term-id="%d" data-context="taxonomy"></div>',
        esc_attr(wp_json_encode($vue_group_data)),
        esc_attr(wp_json_encode($saved_values)),
        $term_id
      );
      echo '</td>';
      echo '</tr>';
    } else {
      // For add form
      echo '<div class="form-field">';
      echo '<label>' . esc_html($group['title']) . '</label>';
      printf(
        '<div class="uixpress-custom-fields-app uixpress-taxonomy-fields" data-field-group="%s" data-saved-values="%s" data-term-id="%d" data-context="taxonomy"></div>',
        esc_attr(wp_json_encode($vue_group_data)),
        esc_attr(wp_json_encode($saved_values)),
        $term_id
      );
      echo '</div>';
    }
  }

  /**
   * Save custom fields for a term
   *
   * @param int $term_id Term ID
   * @param int $tt_id Term taxonomy ID
   */
  public function save_term_fields($term_id, $tt_id)
  {
    // Check permissions
    $term = get_term($term_id);
    if (!$term || is_wp_error($term)) {
      return;
    }
    
    $taxonomy = $term->taxonomy;
    $taxonomy_obj = get_taxonomy($taxonomy);
    
    if (!$taxonomy_obj || !current_user_can($taxonomy_obj->cap->edit_terms)) {
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

    // Process each field group
    foreach ($field_groups as $group) {
      if (empty($group['active'])) {
        continue;
      }
      
      // Check if this field group applies to this taxonomy
      $location = $group['location'] ?? [];
      if (!empty($location) && !LocationRuleEvaluator::should_show_for_taxonomy($location, $taxonomy, $term)) {
        continue;
      }
      
      $nonce_name = 'uixpress_tax_nonce_' . $group['id'];
      if (isset($_POST[$nonce_name]) && wp_verify_nonce($_POST[$nonce_name], 'uixpress_taxonomy_fields_' . $group['id'])) {
        // Save fields for this group using centralized sanitizer
        foreach ($group['fields'] ?? [] as $field) {
          if (isset($custom_fields[$field['name']])) {
            $value = FieldValueSanitizer::sanitize($custom_fields[$field['name']], $field);
            update_term_meta($term_id, $field['name'], $value);
          } else {
            // Handle checkboxes (not sent when unchecked)
            if ($field['type'] === 'true_false') {
              update_term_meta($term_id, $field['name'], '0');
            }
          }
        }
      }
    }
  }
}

