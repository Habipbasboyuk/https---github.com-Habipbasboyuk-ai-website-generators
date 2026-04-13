<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class CustomFieldsLoader
 *
 * Main orchestrator for custom fields across all WordPress contexts.
 * Initializes and coordinates all context-specific managers.
 */
class CustomFieldsLoader
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
   * @var array Context managers that have been initialized
   */
  private $managers = [];

  /**
   * @var self Singleton instance
   */
  private static $instance = null;

  /**
   * Get singleton instance
   *
   * @return self
   */
  public static function instance()
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Initialize the loader
   */
  private function __construct()
  {
    $json_file_path = WP_CONTENT_DIR . '/uixpress-custom-fields.json';
    $this->repository = new FieldGroupRepository($json_file_path);
    $this->evaluator = new LocationRuleEvaluator();
  }

  /**
   * Initialize all custom fields functionality
   * This is the main entry point - call this once during plugin init
   */
  public function init()
  {
    // Don't do anything if no active field groups
    if (!$this->repository->has_active_groups()) {
      return;
    }

    // Register hooks for all supported contexts
    $this->register_post_hooks();
    $this->register_taxonomy_hooks();
    $this->register_user_hooks();
    $this->register_comment_hooks();
    $this->register_attachment_hooks();
    
    // Register centralized script loading
    add_action('admin_footer', [$this, 'maybe_load_scripts']);
    add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
  }

  /**
   * Register post-related hooks (meta boxes)
   */
  private function register_post_hooks()
  {
    // Check if any field groups target posts/pages
    if (!$this->has_context_field_groups('post')) {
      return;
    }

    $this->managers['post'] = new MetaBoxManager($this->repository, $this->evaluator);
    
    add_action('add_meta_boxes', [$this->managers['post'], 'register_meta_boxes']);
    add_action('save_post', [$this, 'save_post_fields']);
  }

  /**
   * Register taxonomy-related hooks
   */
  private function register_taxonomy_hooks()
  {
    // Check if any field groups target taxonomies
    if (!$this->has_context_field_groups('taxonomy')) {
      return;
    }

    $this->managers['taxonomy'] = new TaxonomyMetaBoxManager($this->repository, $this->evaluator);
    $this->managers['taxonomy']->register_taxonomy_hooks();
  }

  /**
   * Register user-related hooks
   */
  private function register_user_hooks()
  {
    // Check if any field groups target users
    if (!$this->has_context_field_groups('user')) {
      return;
    }

    $this->managers['user'] = new UserMetaBoxManager($this->repository, $this->evaluator);
    $this->managers['user']->register_hooks();
  }

  /**
   * Register comment-related hooks
   */
  private function register_comment_hooks()
  {
    // Check if any field groups target comments
    if (!$this->has_context_field_groups('comment')) {
      return;
    }

    $this->managers['comment'] = new CommentMetaBoxManager($this->repository, $this->evaluator);
    $this->managers['comment']->register_hooks();
  }

  /**
   * Register attachment-related hooks
   */
  private function register_attachment_hooks()
  {
    // Check if any field groups target attachments
    if (!$this->has_context_field_groups('attachment')) {
      return;
    }

    $this->managers['attachment'] = new AttachmentMetaBoxManager($this->repository, $this->evaluator);
    $this->managers['attachment']->register_hooks();
  }

  /**
   * Check if any field groups target a specific context
   *
   * @param string $context The context to check (post, taxonomy, user, comment, attachment)
   * @return bool
   */
  private function has_context_field_groups($context)
  {
    $field_groups = $this->repository->read();
    
    if (!is_array($field_groups)) {
      return false;
    }

    foreach ($field_groups as $group) {
      if (empty($group['active'])) {
        continue;
      }

      $location = $group['location'] ?? [];
      if (LocationRuleEvaluator::has_context_rules($location, $context)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Save post custom fields
   *
   * @param int $post_id Post ID
   */
  public function save_post_fields($post_id)
  {
    // Check if autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return;
    }

    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
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

    // Process each active field group
    foreach ($field_groups as $group) {
      if (empty($group['active'])) {
        continue;
      }
      
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
            $value = FieldValueSanitizer::sanitize($custom_fields[$field['name']], $field);
            update_post_meta($post_id, $field['name'], $value);
          } else {
            // Handle checkboxes (not sent when unchecked)
            if ($field['type'] === 'true_false') {
              update_post_meta($post_id, $field['name'], '0');
            }
          }
        }
      }
    }
  }

  /**
   * Maybe load scripts in admin footer based on current screen
   */
  public function maybe_load_scripts()
  {
    $screen = get_current_screen();
    if (!$screen) {
      return;
    }

    $should_load = false;

    // Post edit screens (classic editor)
    if (in_array($screen->base, ['post', 'post-new'])) {
      // Skip if block editor is active
      if (function_exists('use_block_editor_for_post_type') && use_block_editor_for_post_type($screen->post_type)) {
        return;
      }
      $should_load = isset($this->managers['post']);
    }

    // Taxonomy term screens
    if (in_array($screen->base, ['edit-tags', 'term'])) {
      $should_load = isset($this->managers['taxonomy']);
    }

    // User edit screens
    if (in_array($screen->base, ['user-edit', 'profile', 'user-new'])) {
      $should_load = isset($this->managers['user']);
    }

    // Comment edit screen
    if ($screen->base === 'comment') {
      $should_load = isset($this->managers['comment']);
    }

    // Attachment edit screen
    if ($screen->base === 'attachment' || ($screen->base === 'post' && $screen->post_type === 'attachment')) {
      $should_load = isset($this->managers['attachment']);
    }

    if ($should_load) {
      CustomFieldsScriptLoader::load_assets();
    }
  }

  /**
   * Enqueue block editor assets
   */
  public function enqueue_block_editor_assets()
  {
    if (!$this->repository->has_active_groups()) {
      return;
    }

    // Only load for post types that have field groups
    if (isset($this->managers['post'])) {
      CustomFieldsScriptLoader::load_assets();
    }
  }

  /**
   * Get the repository instance
   *
   * @return FieldGroupRepository
   */
  public function get_repository()
  {
    return $this->repository;
  }

  /**
   * Get the evaluator instance
   *
   * @return LocationRuleEvaluator
   */
  public function get_evaluator()
  {
    return $this->evaluator;
  }

  /**
   * Get a specific manager
   *
   * @param string $context The context (post, taxonomy, user, comment, attachment)
   * @return object|null The manager instance or null
   */
  public function get_manager($context)
  {
    return $this->managers[$context] ?? null;
  }
}

