<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class LocationRuleEvaluator
 *
 * Evaluates location rules to determine if field groups should be displayed
 */
class LocationRuleEvaluator
{
  /**
   * Get taxonomies from location rules
   * 
   * This extracts all taxonomies that could potentially match the location rules.
   *
   * @param array $location Location rules
   * @return array Taxonomy names
   */
  public static function get_location_taxonomies($location)
  {
    $taxonomies = [];
    $all_taxonomies = get_taxonomies(['public' => true], 'names');
    
    foreach ($location as $group) {
      foreach ($group as $rule) {
        $param = $rule['param'] ?? '';
        $operator = $rule['operator'] ?? '==';
        $value = $rule['value'] ?? '';
        
        if ($param === 'taxonomy') {
          if ($operator === '==' && $value === 'all') {
            $taxonomies = array_merge($taxonomies, $all_taxonomies);
          } elseif ($operator === '==') {
            $taxonomies[] = $value;
          } elseif ($operator === '!=' && $value !== 'all') {
            // Not equal to specific taxonomy - include all except that one
            $taxonomies = array_merge($taxonomies, array_diff($all_taxonomies, [$value]));
          }
        } elseif ($param === 'taxonomy_term') {
          // Extract taxonomy from term value (format: taxonomy:term_id)
          if (strpos($value, ':') !== false) {
            list($tax_name, $term_id) = explode(':', $value, 2);
            $taxonomies[] = $tax_name;
          }
        }
      }
    }
    
    return array_unique($taxonomies);
  }

  /**
   * Check if a field group should be displayed for a taxonomy term
   * 
   * Evaluates all location rules against the current taxonomy context.
   * Uses OR logic between groups and AND logic within groups.
   *
   * @param array $location Location rules
   * @param string $taxonomy Taxonomy name
   * @param \WP_Term|null $term Term object (null for add form)
   * @return bool Whether the field group should be displayed
   */
  public static function should_show_for_taxonomy($location, $taxonomy, $term = null)
  {
    if (empty($location)) {
      return false;
    }
    
    // OR logic between groups - if any group matches, show the field group
    foreach ($location as $group) {
      if (self::evaluate_taxonomy_location_group($group, $taxonomy, $term)) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Evaluate a single location group for taxonomy context (AND logic within the group)
   *
   * @param array $group Array of rules
   * @param string $taxonomy Taxonomy name
   * @param \WP_Term|null $term Term object
   * @return bool Whether all rules in the group match
   */
  private static function evaluate_taxonomy_location_group($group, $taxonomy, $term)
  {
    if (empty($group)) {
      return false;
    }
    
    $has_taxonomy_rule = false;
    
    // Check if this group has any taxonomy-related rules
    foreach ($group as $rule) {
      $param = $rule['param'] ?? '';
      if (in_array($param, ['taxonomy', 'taxonomy_term'])) {
        $has_taxonomy_rule = true;
        break;
      }
    }
    
    // If no taxonomy rules in this group, it doesn't match taxonomy screens
    if (!$has_taxonomy_rule) {
      return false;
    }
    
    // AND logic - all rules must match
    foreach ($group as $rule) {
      if (!self::evaluate_taxonomy_location_rule($rule, $taxonomy, $term)) {
        return false;
      }
    }
    
    return true;
  }

  /**
   * Evaluate a single location rule for taxonomy context
   *
   * @param array $rule Rule with param, operator, value
   * @param string $taxonomy Taxonomy name
   * @param \WP_Term|null $term Term object
   * @return bool Whether the rule matches
   */
  private static function evaluate_taxonomy_location_rule($rule, $taxonomy, $term)
  {
    $param = $rule['param'] ?? '';
    $operator = $rule['operator'] ?? '==';
    $value = $rule['value'] ?? '';
    
    $match = false;
    
    switch ($param) {
      case 'taxonomy':
        if ($value === 'all') {
          $match = true;
        } else {
          $match = ($taxonomy === $value);
        }
        break;
        
      case 'taxonomy_term':
        if (!$term) {
          // On add form, match based on taxonomy only if the value's taxonomy matches
          if (strpos($value, ':') !== false) {
            list($tax_name, $term_id) = explode(':', $value, 2);
            $match = ($taxonomy === $tax_name);
          }
        } else {
          // On edit form, check specific term
          if (strpos($value, ':') !== false) {
            list($tax_name, $term_id) = explode(':', $value, 2);
            $match = ($taxonomy === $tax_name && (int)$term->term_id === (int)$term_id);
          } else {
            // Legacy format: just term ID
            $match = ((int)$term->term_id === (int)$value);
          }
        }
        break;
        
      case 'current_user':
        $match = self::match_current_user($value);
        break;
        
      case 'current_user_role':
        $match = self::match_current_user_role($value);
        break;
        
      // Rules that don't apply to taxonomy terms - always pass (handled elsewhere)
      case 'post_type':
      case 'post_template':
      case 'post_status':
      case 'post_format':
      case 'post_category':
      case 'post_taxonomy':
      case 'post':
      case 'page_template':
      case 'page_type':
      case 'page_parent':
      case 'page':
      case 'attachment':
      case 'user':
      case 'user_form':
      case 'user_role':
      case 'comment':
      case 'nav_menu':
      case 'nav_menu_item':
      case 'widget':
      case 'options_page':
      case 'block':
        // These don't apply to taxonomy screens - skip them (pass by default)
        $match = true;
        break;
        
      default:
        // Unknown rule types pass by default
        $match = true;
        break;
    }
    
    // Apply operator
    if ($operator === '!=') {
      return !$match;
    }
    
    return $match;
  }

  /**
   * Check if location rules contain rules for a specific context
   *
   * @param array $location Location rules
   * @param string $context Context type (post, taxonomy, user, comment, attachment)
   * @return bool
   */
  public static function has_context_rules($location, $context)
  {
    if (empty($location)) {
      return false;
    }

    // Define which params belong to each context
    $context_params = [
      'post' => ['post_type', 'post_template', 'post_status', 'post_format', 'post_category', 'post_taxonomy', 'post', 'page_template', 'page_type', 'page_parent', 'page', 'block'],
      'taxonomy' => ['taxonomy', 'taxonomy_term'],
      'user' => ['user', 'user_form', 'user_role'],
      'comment' => ['comment'],
      'attachment' => ['attachment'],
    ];

    $target_params = $context_params[$context] ?? [];
    
    foreach ($location as $group) {
      foreach ($group as $rule) {
        $param = $rule['param'] ?? '';
        if (in_array($param, $target_params)) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Check if a field group should be displayed for a user
   *
   * @param array $location Location rules
   * @param \WP_User|null $user User object (null for add new)
   * @param string $context 'add' or 'edit'
   * @return bool
   */
  public static function should_show_for_user($location, $user = null, $context = 'edit')
  {
    if (empty($location)) {
      return false;
    }
    
    foreach ($location as $group) {
      if (self::evaluate_user_location_group($group, $user, $context)) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Evaluate a single location group for user context
   *
   * @param array $group Array of rules
   * @param \WP_User|null $user User object
   * @param string $context 'add' or 'edit'
   * @return bool
   */
  private static function evaluate_user_location_group($group, $user, $context)
  {
    if (empty($group)) {
      return false;
    }
    
    $has_user_rule = false;
    
    foreach ($group as $rule) {
      $param = $rule['param'] ?? '';
      if (in_array($param, ['user', 'user_form', 'user_role'])) {
        $has_user_rule = true;
        break;
      }
    }
    
    if (!$has_user_rule) {
      return false;
    }
    
    foreach ($group as $rule) {
      if (!self::evaluate_user_location_rule($rule, $user, $context)) {
        return false;
      }
    }
    
    return true;
  }

  /**
   * Evaluate a single location rule for user context
   *
   * @param array $rule Rule with param, operator, value
   * @param \WP_User|null $user User object
   * @param string $context 'add' or 'edit'
   * @return bool
   */
  private static function evaluate_user_location_rule($rule, $user, $context)
  {
    $param = $rule['param'] ?? '';
    $operator = $rule['operator'] ?? '==';
    $value = $rule['value'] ?? '';
    
    $match = false;
    
    switch ($param) {
      case 'user_form':
        if ($value === 'all') {
          $match = true;
        } elseif ($value === 'add') {
          $match = ($context === 'add');
        } elseif ($value === 'edit') {
          $match = ($context === 'edit');
        }
        break;
        
      case 'user_role':
        if ($value === 'all') {
          $match = true;
        } elseif ($user) {
          $match = in_array($value, (array)$user->roles);
        } else {
          // For add form without a user, match if checking for any role
          $match = true;
        }
        break;
        
      case 'user':
        if ($user) {
          $match = ((int)$user->ID === (int)$value);
        }
        break;
        
      case 'current_user':
        $match = self::match_current_user($value);
        break;
        
      case 'current_user_role':
        $match = self::match_current_user_role($value);
        break;
        
      default:
        $match = true;
        break;
    }
    
    if ($operator === '!=') {
      return !$match;
    }
    
    return $match;
  }

  /**
   * Check if a field group should be displayed for a comment
   *
   * @param array $location Location rules
   * @param \WP_Comment $comment Comment object
   * @return bool
   */
  public static function should_show_for_comment($location, $comment)
  {
    if (empty($location)) {
      return false;
    }
    
    foreach ($location as $group) {
      if (self::evaluate_comment_location_group($group, $comment)) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Evaluate a single location group for comment context
   *
   * @param array $group Array of rules
   * @param \WP_Comment $comment Comment object
   * @return bool
   */
  private static function evaluate_comment_location_group($group, $comment)
  {
    if (empty($group)) {
      return false;
    }
    
    $has_comment_rule = false;
    
    foreach ($group as $rule) {
      if (($rule['param'] ?? '') === 'comment') {
        $has_comment_rule = true;
        break;
      }
    }
    
    if (!$has_comment_rule) {
      return false;
    }
    
    foreach ($group as $rule) {
      if (!self::evaluate_comment_location_rule($rule, $comment)) {
        return false;
      }
    }
    
    return true;
  }

  /**
   * Evaluate a single location rule for comment context
   *
   * @param array $rule Rule with param, operator, value
   * @param \WP_Comment $comment Comment object
   * @return bool
   */
  private static function evaluate_comment_location_rule($rule, $comment)
  {
    $param = $rule['param'] ?? '';
    $operator = $rule['operator'] ?? '==';
    $value = $rule['value'] ?? '';
    
    $match = false;
    
    switch ($param) {
      case 'comment':
        if ($value === 'all') {
          $match = true;
        } else {
          // Value is a post type - check if comment is on that post type
          $post = get_post($comment->comment_post_ID);
          $match = ($post && $post->post_type === $value);
        }
        break;
        
      case 'current_user':
        $match = self::match_current_user($value);
        break;
        
      case 'current_user_role':
        $match = self::match_current_user_role($value);
        break;
        
      default:
        $match = true;
        break;
    }
    
    if ($operator === '!=') {
      return !$match;
    }
    
    return $match;
  }

  /**
   * Check if a field group should be displayed for an attachment
   *
   * @param array $location Location rules
   * @param \WP_Post $post Attachment post object
   * @return bool
   */
  public static function should_show_for_attachment($location, $post)
  {
    if (empty($location)) {
      return false;
    }
    
    foreach ($location as $group) {
      if (self::evaluate_attachment_location_group($group, $post)) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Evaluate a single location group for attachment context
   *
   * @param array $group Array of rules
   * @param \WP_Post $post Attachment post object
   * @return bool
   */
  private static function evaluate_attachment_location_group($group, $post)
  {
    if (empty($group)) {
      return false;
    }
    
    $has_attachment_rule = false;
    
    foreach ($group as $rule) {
      if (($rule['param'] ?? '') === 'attachment') {
        $has_attachment_rule = true;
        break;
      }
    }
    
    if (!$has_attachment_rule) {
      return false;
    }
    
    foreach ($group as $rule) {
      if (!self::evaluate_attachment_location_rule($rule, $post)) {
        return false;
      }
    }
    
    return true;
  }

  /**
   * Evaluate a single location rule for attachment context
   *
   * @param array $rule Rule with param, operator, value
   * @param \WP_Post $post Attachment post object
   * @return bool
   */
  private static function evaluate_attachment_location_rule($rule, $post)
  {
    $param = $rule['param'] ?? '';
    $operator = $rule['operator'] ?? '==';
    $value = $rule['value'] ?? '';
    
    $match = false;
    
    switch ($param) {
      case 'attachment':
        if ($value === 'all') {
          $match = true;
        } else {
          // Value is a mime type category (image, video, audio, application)
          $mime_type = get_post_mime_type($post);
          $type_parts = explode('/', $mime_type);
          $main_type = $type_parts[0] ?? '';
          $match = ($main_type === $value);
        }
        break;
        
      case 'current_user':
        $match = self::match_current_user($value);
        break;
        
      case 'current_user_role':
        $match = self::match_current_user_role($value);
        break;
        
      default:
        $match = true;
        break;
    }
    
    if ($operator === '!=') {
      return !$match;
    }
    
    return $match;
  }

  /**
   * Get post types from location rules
   * 
   * This extracts all post types that could potentially match the location rules.
   * For complex rules, we register on all potential post types and filter at render time.
   *
   * @param array $location Location rules
   * @return array Post types
   */
  public static function get_location_post_types($location)
  {
    $post_types = [];
    $all_post_types = get_post_types(['public' => true], 'names');
    
    foreach ($location as $group) {
      $group_post_types = [];
      $has_post_type_rule = false;
      $has_page_rule = false;
      $needs_all_post_types = false;
      
      foreach ($group as $rule) {
        $param = $rule['param'] ?? '';
        $operator = $rule['operator'] ?? '==';
        $value = $rule['value'] ?? '';
        
        switch ($param) {
          case 'post_type':
            $has_post_type_rule = true;
            if ($operator === '==' && $value === 'all') {
              $group_post_types = array_merge($group_post_types, $all_post_types);
            } elseif ($operator === '==') {
              $group_post_types[] = $value;
            } elseif ($operator === '!=' && $value !== 'all') {
              // Not equal to specific type - include all except that type
              $group_post_types = array_merge($group_post_types, array_diff($all_post_types, [$value]));
            }
            break;
            
          case 'post_template':
          case 'post_status':
          case 'post_format':
          case 'post_category':
          case 'post_taxonomy':
          case 'post':
            // These rules apply to posts - if no post_type specified, default to 'post'
            if (!$has_post_type_rule) {
              $group_post_types[] = 'post';
            }
            break;
            
          case 'page_template':
          case 'page_type':
          case 'page_parent':
          case 'page':
            // These rules apply to pages
            $has_page_rule = true;
            $group_post_types[] = 'page';
            break;
            
          case 'attachment':
            $group_post_types[] = 'attachment';
            break;
            
          case 'taxonomy':
          case 'taxonomy_term':
            // Taxonomy rules - we'll handle these on taxonomy edit screens
            // For now, skip these for post meta boxes
            break;
            
          case 'user':
          case 'user_form':
          case 'user_role':
            // User rules - handled separately on user edit screens
            break;
            
          case 'comment':
            // Comment rules - handled on comment screens
            break;
            
          case 'nav_menu':
          case 'nav_menu_item':
            // Menu rules - handled on menu screens
            break;
            
          case 'widget':
            // Widget rules - handled in widgets
            break;
            
          case 'block':
            // Block rules - all post types with block editor support
            $needs_all_post_types = true;
            break;
            
          case 'options_page':
            // Options page rules - handled separately
            break;
            
          case 'current_user':
          case 'current_user_role':
            // Current user rules - these are evaluated at render time
            // Don't limit post types based on these
            if (!$has_post_type_rule && !$has_page_rule) {
              $needs_all_post_types = true;
            }
            break;
        }
      }
      
      if ($needs_all_post_types) {
        $group_post_types = array_merge($group_post_types, $all_post_types);
      }
      
      $post_types = array_merge($post_types, $group_post_types);
    }
    
    return array_unique($post_types);
  }

  /**
   * Check if a field group should be displayed for a specific post
   * 
   * Evaluates all location rules against the current post context.
   * Uses OR logic between groups and AND logic within groups.
   *
   * @param array $location Location rules
   * @param \WP_Post $post Post object
   * @return bool Whether the field group should be displayed
   */
  public static function should_show_for_post($location, $post)
  {
    if (empty($location)) {
      return false;
    }
    
    // OR logic between groups - if any group matches, show the field group
    foreach ($location as $group) {
      if (self::evaluate_location_group($group, $post)) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Evaluate a single location group (AND logic within the group)
   *
   * @param array $group Array of rules
   * @param \WP_Post $post Post object
   * @return bool Whether all rules in the group match
   */
  private static function evaluate_location_group($group, $post)
  {
    if (empty($group)) {
      return false;
    }
    
    // AND logic - all rules must match
    foreach ($group as $rule) {
      if (!self::evaluate_location_rule($rule, $post)) {
        return false;
      }
    }
    
    return true;
  }

  /**
   * Evaluate a single location rule
   *
   * @param array $rule Rule with param, operator, value
   * @param \WP_Post $post Post object
   * @return bool Whether the rule matches
   */
  private static function evaluate_location_rule($rule, $post)
  {
    $param = $rule['param'] ?? '';
    $operator = $rule['operator'] ?? '==';
    $value = $rule['value'] ?? '';
    
    $match = false;
    
    switch ($param) {
      case 'post_type':
        $match = self::match_post_type($post, $value);
        break;
        
      case 'post_template':
        $match = self::match_post_template($post, $value);
        break;
        
      case 'post_status':
        $match = self::match_post_status($post, $value);
        break;
        
      case 'post_format':
        $match = self::match_post_format($post, $value);
        break;
        
      case 'post_category':
        $match = self::match_post_category($post, $value);
        break;
        
      case 'post_taxonomy':
        $match = self::match_post_taxonomy($post, $value);
        break;
        
      case 'post':
        $match = self::match_specific_post($post, $value);
        break;
        
      case 'page_template':
        $match = self::match_page_template($post, $value);
        break;
        
      case 'page_type':
        $match = self::match_page_type($post, $value);
        break;
        
      case 'page_parent':
        $match = self::match_page_parent($post, $value);
        break;
        
      case 'page':
        $match = self::match_specific_page($post, $value);
        break;
        
      case 'attachment':
        $match = self::match_attachment($post, $value);
        break;
        
      case 'taxonomy':
        $match = self::match_taxonomy($post, $value);
        break;
        
      case 'taxonomy_term':
        $match = self::match_taxonomy_term($post, $value);
        break;
        
      case 'current_user':
        $match = self::match_current_user($value);
        break;
        
      case 'current_user_role':
        $match = self::match_current_user_role($value);
        break;
        
      // Rules that don't apply to posts - always pass (handled elsewhere)
      case 'user':
      case 'user_form':
      case 'user_role':
      case 'comment':
      case 'nav_menu':
      case 'nav_menu_item':
      case 'widget':
      case 'options_page':
      case 'block':
        $match = true;
        break;
        
      default:
        // Unknown rule types pass by default
        $match = true;
        break;
    }
    
    // Apply operator
    if ($operator === '!=') {
      return !$match;
    }
    
    return $match;
  }

  /**
   * Match post type
   */
  private static function match_post_type($post, $value)
  {
    if ($value === 'all') {
      return true;
    }
    return $post->post_type === $value;
  }

  /**
   * Match post template (for posts that support templates)
   */
  private static function match_post_template($post, $value)
  {
    $template = get_page_template_slug($post);
    if ($value === 'default' || empty($value)) {
      return empty($template);
    }
    return $template === $value;
  }

  /**
   * Match post status
   */
  private static function match_post_status($post, $value)
  {
    if ($value === 'all') {
      return true;
    }
    return $post->post_status === $value;
  }

  /**
   * Match post format
   */
  private static function match_post_format($post, $value)
  {
    $format = get_post_format($post);
    if ($value === 'standard' || empty($value)) {
      return $format === false || $format === 'standard';
    }
    return $format === $value;
  }

  /**
   * Match post category
   */
  private static function match_post_category($post, $value)
  {
    if ($value === 'all') {
      return has_category('', $post);
    }
    
    // Value can be category ID or slug
    if (is_numeric($value)) {
      return has_category((int) $value, $post);
    }
    
    return has_category($value, $post);
  }

  /**
   * Match post taxonomy (check if post has any term in taxonomy)
   */
  private static function match_post_taxonomy($post, $value)
  {
    if ($value === 'all') {
      return true;
    }
    
    // Check if post has any term in this taxonomy
    $terms = get_the_terms($post->ID, $value);
    return !empty($terms) && !is_wp_error($terms);
  }

  /**
   * Match specific post by ID
   */
  private static function match_specific_post($post, $value)
  {
    return (int) $post->ID === (int) $value;
  }

  /**
   * Match page template
   */
  private static function match_page_template($post, $value)
  {
    if ($post->post_type !== 'page') {
      return false;
    }
    
    $template = get_page_template_slug($post);
    if ($value === 'default' || empty($value)) {
      return empty($template);
    }
    return $template === $value;
  }

  /**
   * Match page type (front_page, posts_page, top_level, parent, child)
   */
  private static function match_page_type($post, $value)
  {
    if ($post->post_type !== 'page') {
      return false;
    }
    
    switch ($value) {
      case 'front_page':
        return (int) get_option('page_on_front') === $post->ID;
        
      case 'posts_page':
        return (int) get_option('page_for_posts') === $post->ID;
        
      case 'top_level':
        return $post->post_parent == 0;
        
      case 'parent':
        // Has children
        $children = get_pages(['parent' => $post->ID, 'number' => 1]);
        return !empty($children);
        
      case 'child':
        return $post->post_parent > 0;
    }
    
    return false;
  }

  /**
   * Match page parent
   */
  private static function match_page_parent($post, $value)
  {
    if ($post->post_type !== 'page') {
      return false;
    }
    
    return (int) $post->post_parent === (int) $value;
  }

  /**
   * Match specific page by ID
   */
  private static function match_specific_page($post, $value)
  {
    if ($post->post_type !== 'page') {
      return false;
    }
    
    return (int) $post->ID === (int) $value;
  }

  /**
   * Match attachment type
   */
  private static function match_attachment($post, $value)
  {
    if ($post->post_type !== 'attachment') {
      return false;
    }
    
    if ($value === 'all') {
      return true;
    }
    
    $mime_type = get_post_mime_type($post);
    $type_parts = explode('/', $mime_type);
    $main_type = $type_parts[0] ?? '';
    
    return $main_type === $value;
  }

  /**
   * Match current user state
   */
  private static function match_current_user($value)
  {
    switch ($value) {
      case 'logged_in':
        return is_user_logged_in();
        
      case 'logged_out':
        return !is_user_logged_in();
        
      case 'viewing_front':
        return !is_admin();
        
      case 'viewing_back':
        return is_admin();
    }
    
    return false;
  }

  /**
   * Match current user role
   */
  private static function match_current_user_role($value)
  {
    if (!is_user_logged_in()) {
      return false;
    }
    
    $user = wp_get_current_user();
    
    if ($value === 'all') {
      return true;
    }
    
    return in_array($value, $user->roles, true);
  }

  /**
   * Match taxonomy - check if post has any terms in the given taxonomy
   */
  private static function match_taxonomy($post, $value)
  {
    if ($value === 'all') {
      // Check if post has terms in any taxonomy
      $taxonomies = get_post_taxonomies($post);
      foreach ($taxonomies as $taxonomy) {
        $terms = get_the_terms($post->ID, $taxonomy);
        if (!empty($terms) && !is_wp_error($terms)) {
          return true;
        }
      }
      return false;
    }
    
    // Check if post has any term in this specific taxonomy
    $terms = get_the_terms($post->ID, $value);
    return !empty($terms) && !is_wp_error($terms);
  }

  /**
   * Match taxonomy term - check if post has a specific term
   * Value format: "taxonomy_name:term_id" or just "term_id" for category
   */
  private static function match_taxonomy_term($post, $value)
  {
    if (strpos($value, ':') !== false) {
      // Format: taxonomy:term_id
      list($taxonomy, $term_id) = explode(':', $value, 2);
      return has_term((int) $term_id, $taxonomy, $post);
    }
    
    // Legacy format: just term ID (assume category)
    return has_term((int) $value, 'category', $post);
  }

  /**
   * Check if a field group should be displayed for an option page
   *
   * @param array $location Location rules
   * @param string $page_slug Option page slug
   * @return bool Whether the field group should be displayed
   */
  public static function should_show_for_option_page($location, $page_slug)
  {
    if (empty($location)) {
      return false;
    }
    
    foreach ($location as $group) {
      if (self::evaluate_option_page_location_group($group, $page_slug)) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Evaluate a single location group for option page context
   *
   * @param array $group Array of rules
   * @param string $page_slug Option page slug
   * @return bool Whether all rules in the group match
   */
  private static function evaluate_option_page_location_group($group, $page_slug)
  {
    if (empty($group)) {
      return false;
    }
    
    $has_option_page_rule = false;
    
    foreach ($group as $rule) {
      if (($rule['param'] ?? '') === 'options_page') {
        $has_option_page_rule = true;
        break;
      }
    }
    
    if (!$has_option_page_rule) {
      return false;
    }
    
    foreach ($group as $rule) {
      if (!self::evaluate_option_page_location_rule($rule, $page_slug)) {
        return false;
      }
    }
    
    return true;
  }

  /**
   * Evaluate a single location rule for option page context
   *
   * @param array $rule Rule with param, operator, value
   * @param string $page_slug Option page slug
   * @return bool Whether the rule matches
   */
  private static function evaluate_option_page_location_rule($rule, $page_slug)
  {
    $param = $rule['param'] ?? '';
    $operator = $rule['operator'] ?? '==';
    $value = $rule['value'] ?? '';
    
    $match = false;
    
    switch ($param) {
      case 'options_page':
        $match = ($page_slug === $value);
        break;
        
      case 'current_user':
        $match = self::match_current_user($value);
        break;
        
      case 'current_user_role':
        $match = self::match_current_user_role($value);
        break;
        
      default:
        // Other rules pass by default for option pages
        $match = true;
        break;
    }
    
    if ($operator === '!=') {
      return !$match;
    }
    
    return $match;
  }

  /**
   * Get option page slugs from location rules
   *
   * @param array $location Location rules
   * @return array Option page slugs
   */
  public static function get_location_option_pages($location)
  {
    $option_pages = [];
    
    foreach ($location as $group) {
      foreach ($group as $rule) {
        $param = $rule['param'] ?? '';
        $operator = $rule['operator'] ?? '==';
        $value = $rule['value'] ?? '';
        
        if ($param === 'options_page' && $operator === '==') {
          $option_pages[] = $value;
        }
      }
    }
    
    return array_unique($option_pages);
  }
}

