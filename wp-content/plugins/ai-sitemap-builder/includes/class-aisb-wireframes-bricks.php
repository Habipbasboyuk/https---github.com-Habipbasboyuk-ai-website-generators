<?php

if (!defined('ABSPATH')) exit;

/**
 * Bricks template integration for wireframes.
 * Queries, groups, and picks Bricks templates by section type.
 */
class AISB_Wireframes_Bricks {

  /**
   * Query all published Bricks templates and group them by WordPress tag slug.
   * Falls back to `_bricks_template_type` meta when a template has no tags.
   * Result is statically cached per request.
   *
   * @return array<string, list<array{id:int,title:string,ttype:string,tags:string[],shortcode:string}>>
   */
  public function get_bricks_templates_by_type(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    if (!post_type_exists('bricks_template')) {
      $cache = [];
      return $cache;
    }

    $posts = get_posts([
      'post_type'      => 'bricks_template',
      'post_status'    => 'publish',
      'posts_per_page' => 500,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'no_found_rows'  => true,
    ]);

    $by_type = [];

    foreach ($posts as $post) {
      $id    = (int) $post->ID;

      $bricks_data_meta = get_post_meta($id, '_bricks_data', true);
      $content_2 = get_post_meta($id, '_bricks_page_content_2', true);
      $header_2  = get_post_meta($id, '_bricks_page_header_2', true);
      $footer_2  = get_post_meta($id, '_bricks_page_footer_2', true);

      if (empty($bricks_data_meta) && empty($content_2) && empty($header_2) && empty($footer_2)) {
        continue;
      }

      $title = (string) $post->post_title;
      if (strpos($title, '[AI]') === 0) {
        continue;
      }

      $ttype = (string) (get_post_meta($id, '_bricks_template_type', true) ?: '');
        $tags_raw  = get_the_terms($id, 'template_tag');
        $tags = [];
        if (!empty($tags_raw) && !is_wp_error($tags_raw)) {
            $tags = wp_list_pluck($tags_raw, 'slug');
        }
      $type_keys = array_map('strtolower', (array) $tags);

      if (empty($type_keys) && $ttype !== '') {
        $type_keys = [strtolower($ttype)];
      }

      $entry = [
        'id'        => $id,
        'title'     => $title,
        'ttype'     => $ttype,
        'tags'      => $type_keys,
        'shortcode' => '[bricks_template id="' . $id . '"]',
      ];

      foreach ($type_keys as $key) {
        if (!isset($by_type[$key])) $by_type[$key] = [];
        $by_type[$key][] = $entry;
      }
    }

    $cache = $by_type;
    return $cache;
  }

  /**
   * Pick a random Bricks template for $section_type, respecting exclusions.
   * Falls back to configured aliases when no direct match exists.
   *
   * @param array<string,list<array>> $by_type     Output of get_bricks_templates_by_type()
   * @param int[]                     $exclude_ids Template IDs already used on this page
   */
  public function pick_bricks_template(string $section_type, array $by_type, array $exclude_ids = []): ?array {
    $try = function(string $key) use ($by_type, $exclude_ids): ?array {
      $pool = $by_type[strtolower($key)] ?? [];
      if ($exclude_ids) {
        $pool = array_values(array_filter($pool, function($t) use ($exclude_ids) {
          return !in_array((int) $t['id'], $exclude_ids, true);
        }));
      }
      return $pool ? $pool[array_rand($pool)] : null;
    };

    $tpl = $try($section_type);
    if ($tpl) return $tpl;

    foreach ($this->section_type_aliases()[$section_type] ?? [] as $alt) {
      $tpl = $try($alt);
      if ($tpl) return $tpl;
    }

      foreach (['content', 'features', 'generic'] as $alt) {
        $tpl = $try($alt);
        if ($tpl) return $tpl;
      }

      $all_pool = [];
      foreach ($by_type as $k => $templates) {
        if (in_array(strtolower($k), ['header', 'footer'])) continue;
        foreach ($templates as $t) {
          if (!in_array((int) $t['id'], $exclude_ids, true)) {
            $all_pool[$t['id']] = $t;
          }
        }
      }
      if ($all_pool) {
        return $all_pool[array_rand($all_pool)];
      }

      $all_pool = [];
      foreach ($by_type as $k => $templates) {
        if (in_array(strtolower($k), ['header', 'footer'])) continue;
        foreach ($templates as $t) {
          $all_pool[$t['id']] = $t;
        }
      }
      if ($all_pool) {
        return $all_pool[array_rand($all_pool)];
      }

      return null;
    }

  /** Tag aliases so e.g. "features" also matches "feature", "services" etc. */
  public function section_type_aliases(): array {
    return [
      'features'     => ['feature', 'benefits', 'benefit', 'services', 'service'],
      'testimonials' => ['testimonial', 'reviews', 'review'],
      'cta'          => ['call-to-action', 'call_to_action'],
      'faq'          => ['faqs', 'questions'],
      'pricing'      => ['plans', 'plan', 'packages'],
      'social_proof' => ['logos', 'clients', 'brands', 'partners', 'trust'],
      'team'         => ['staff', 'people'],
      'contact_form' => ['contact', 'form', 'contact-form'],
      'header'       => ['nav', 'navigation', 'navbar', 'menu'],
    ];
  }

  /**
   * All unique section-type keys: built-ins merged with available Bricks tags.
   * @return string[]
   */
  public function get_all_section_types(): array {
    $builtin = ['header','hero','features','process','testimonials','pricing','faq','cta','content','team','story','values','contact_form','locations','social_proof','footer','generic'];
    $bricks  = array_keys($this->get_bricks_templates_by_type());
    $merged  = array_unique(array_merge($builtin, $bricks));
    sort($merged);
    return array_values($merged);
  }
}
