<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class LocationDataProvider
 *
 * Provides location data for the UI (post types, taxonomies, users, etc.)
 */
class LocationDataProvider
{
  /**
   * Get available post types for location rules
   *
   * @return array Post types array
   */
  public function get_post_types()
  {
    $post_types = get_post_types(['public' => true], 'objects');
    $result = [];

    foreach ($post_types as $pt) {
      $result[] = [
        'name' => $pt->name,
        'label' => $pt->label,
        'singular' => $pt->labels->singular_name,
      ];
    }

    // Also add custom post types from our JSON
    $custom_cpts_file = WP_CONTENT_DIR . '/uixpress-custom-post-types.json';
    if (file_exists($custom_cpts_file)) {
      $json_content = file_get_contents($custom_cpts_file);
      $custom_cpts = json_decode($json_content, true);
      if (is_array($custom_cpts)) {
        foreach ($custom_cpts as $cpt) {
          if (!empty($cpt['slug']) && !empty($cpt['active'])) {
            // Check if not already in list
            $exists = false;
            foreach ($result as $existing) {
              if ($existing['name'] === $cpt['slug']) {
                $exists = true;
                break;
              }
            }
            if (!$exists) {
              $result[] = [
                'name' => $cpt['slug'],
                'label' => $cpt['name'],
                'singular' => $cpt['singular_name'] ?? $cpt['name'],
              ];
            }
          }
        }
      }
    }

    return $result;
  }

  /**
   * Get available templates for location rules
   *
   * @return array Templates array
   */
  public function get_templates()
  {
    $templates = wp_get_theme()->get_page_templates();
    $result = [
      ['value' => 'default', 'label' => __('Default Template', 'uixpress')],
    ];

    foreach ($templates as $filename => $name) {
      $result[] = [
        'value' => $filename,
        'label' => $name,
      ];
    }

    return $result;
  }

  /**
   * Get all location rule data for the UI
   *
   * @return array Location data
   */
  public function get_location_data()
  {
    return [
      'post_types' => $this->get_ui_post_types(),
      'taxonomies' => $this->get_ui_taxonomies(),
      'taxonomy_terms' => $this->get_ui_taxonomy_terms(),
      'user_roles' => $this->get_ui_user_roles(),
      'users' => $this->get_ui_users(),
      'page_templates' => $this->get_ui_page_templates(),
      'post_formats' => $this->get_ui_post_formats(),
      'nav_menus' => $this->get_ui_nav_menus(),
      'nav_menu_items' => $this->get_ui_nav_menu_items(),
      'widgets' => $this->get_ui_widgets(),
      'options_pages' => $this->get_ui_options_pages(),
    ];
  }

  /**
   * Get post types for location rules UI
   *
   * @return array Post types
   */
  private function get_ui_post_types()
  {
    $post_types = get_post_types(['public' => true], 'objects');
    $result = [
      ['value' => 'all', 'label' => __('All', 'uixpress')],
    ];

    foreach ($post_types as $pt) {
      $result[] = [
        'value' => $pt->name,
        'label' => $pt->label,
      ];
    }

    // Add custom post types from our JSON
    $custom_cpts_file = WP_CONTENT_DIR . '/uixpress-custom-post-types.json';
    if (file_exists($custom_cpts_file)) {
      $json_content = file_get_contents($custom_cpts_file);
      $custom_cpts = json_decode($json_content, true);
      if (is_array($custom_cpts)) {
        foreach ($custom_cpts as $cpt) {
          if (!empty($cpt['slug']) && !empty($cpt['active'])) {
            $exists = false;
            foreach ($result as $existing) {
              if ($existing['value'] === $cpt['slug']) {
                $exists = true;
                break;
              }
            }
            if (!$exists) {
              $result[] = [
                'value' => $cpt['slug'],
                'label' => $cpt['name'],
              ];
            }
          }
        }
      }
    }

    return $result;
  }

  /**
   * Get taxonomies for location rules UI
   *
   * @return array Taxonomies
   */
  private function get_ui_taxonomies()
  {
    $taxonomies = get_taxonomies(['public' => true], 'objects');
    $result = [
      ['value' => 'all', 'label' => __('All', 'uixpress')],
    ];

    foreach ($taxonomies as $tax) {
      $result[] = [
        'value' => $tax->name,
        'label' => $tax->label,
      ];
    }

    return $result;
  }

  /**
   * Get taxonomy terms grouped by taxonomy for location rules UI
   *
   * @return array Taxonomy terms
   */
  private function get_ui_taxonomy_terms()
  {
    $taxonomies = get_taxonomies(['public' => true], 'objects');
    $result = [];

    foreach ($taxonomies as $tax) {
      $terms = get_terms([
        'taxonomy' => $tax->name,
        'hide_empty' => false,
        'number' => 100, // Limit to avoid performance issues
      ]);

      if (!is_wp_error($terms) && !empty($terms)) {
        $tax_terms = [];
        foreach ($terms as $term) {
          $tax_terms[] = [
            'value' => $term->term_id,
            'label' => $term->name,
            'slug' => $term->slug,
          ];
        }
        $result[$tax->name] = [
          'label' => $tax->label,
          'terms' => $tax_terms,
        ];
      }
    }

    return $result;
  }

  /**
   * Get user roles for location rules UI
   *
   * @return array User roles
   */
  private function get_ui_user_roles()
  {
    global $wp_roles;
    $result = [
      ['value' => 'all', 'label' => __('All', 'uixpress')],
    ];

    if (!empty($wp_roles->roles)) {
      foreach ($wp_roles->roles as $role_slug => $role) {
        $result[] = [
          'value' => $role_slug,
          'label' => $role['name'],
        ];
      }
    }

    return $result;
  }

  /**
   * Get users for location rules UI (limited for performance)
   *
   * @return array Users
   */
  private function get_ui_users()
  {
    $users = get_users([
      'number' => 100,
      'orderby' => 'display_name',
      'order' => 'ASC',
    ]);

    $result = [];
    foreach ($users as $user) {
      $result[] = [
        'value' => $user->ID,
        'label' => $user->display_name . ' (' . $user->user_login . ')',
      ];
    }

    return $result;
  }

  /**
   * Get page templates for location rules UI
   *
   * @return array Page templates
   */
  private function get_ui_page_templates()
  {
    $templates = wp_get_theme()->get_page_templates();
    $result = [
      ['value' => 'default', 'label' => __('Default Template', 'uixpress')],
    ];

    foreach ($templates as $filename => $name) {
      $result[] = [
        'value' => $filename,
        'label' => $name,
      ];
    }

    return $result;
  }

  /**
   * Get post formats for location rules UI
   *
   * @return array Post formats
   */
  private function get_ui_post_formats()
  {
    $result = [
      ['value' => 'all', 'label' => __('All', 'uixpress')],
      ['value' => 'standard', 'label' => __('Standard', 'uixpress')],
    ];

    if (current_theme_supports('post-formats')) {
      $formats = get_theme_support('post-formats');
      if (is_array($formats) && !empty($formats[0])) {
        foreach ($formats[0] as $format) {
          $result[] = [
            'value' => $format,
            'label' => ucfirst($format),
          ];
        }
      }
    }

    return $result;
  }

  /**
   * Get navigation menus for location rules UI
   *
   * @return array Navigation menus
   */
  private function get_ui_nav_menus()
  {
    $menus = wp_get_nav_menus();
    $result = [
      ['value' => 'all', 'label' => __('All', 'uixpress')],
    ];

    foreach ($menus as $menu) {
      $result[] = [
        'value' => $menu->term_id,
        'label' => $menu->name,
      ];
    }

    return $result;
  }

  /**
   * Get navigation menu items for location rules UI
   *
   * @return array Navigation menu items
   */
  private function get_ui_nav_menu_items()
  {
    $menus = wp_get_nav_menus();
    $result = [];

    foreach ($menus as $menu) {
      $items = wp_get_nav_menu_items($menu->term_id);
      if (!empty($items)) {
        $menu_items = [];
        foreach ($items as $item) {
          $menu_items[] = [
            'value' => $item->ID,
            'label' => $item->title,
          ];
        }
        $result[$menu->term_id] = [
          'label' => $menu->name,
          'items' => $menu_items,
        ];
      }
    }

    return $result;
  }

  /**
   * Get widget areas for location rules UI
   *
   * @return array Widget areas
   */
  private function get_ui_widgets()
  {
    global $wp_registered_sidebars;
    $result = [
      ['value' => 'all', 'label' => __('All', 'uixpress')],
    ];

    if (!empty($wp_registered_sidebars)) {
      foreach ($wp_registered_sidebars as $sidebar_id => $sidebar) {
        $result[] = [
          'value' => $sidebar_id,
          'label' => $sidebar['name'],
        ];
      }
    }

    return $result;
  }

  /**
   * Get options pages for location rules UI (from UiXpress)
   *
   * @return array Options pages
   */
  private function get_ui_options_pages()
  {
    $result = [];

    // Check if there are any registered options pages
    // This could be extended to support custom options pages
    $options_file = WP_CONTENT_DIR . '/uixpress-options-pages.json';
    if (file_exists($options_file)) {
      $json_content = file_get_contents($options_file);
      $options_pages = json_decode($json_content, true);
      if (is_array($options_pages)) {
        foreach ($options_pages as $page) {
          if (!empty($page['slug']) && !empty($page['active'])) {
            $result[] = [
              'value' => $page['slug'],
              'label' => $page['title'] ?? $page['slug'],
            ];
          }
        }
      }
    }

    return $result;
  }
}

