<?php

if (!defined('ABSPATH')) exit;

class AISB_Enforcer {
      private function section_type_from_name($section_name) {
    $n = strtolower(trim((string)$section_name));
    if ($n === 'navbar') return 'Headers';
    if ($n === 'hero') return 'Hero Sections';
    if ($n === 'footer') return 'Footers';
    if ($n === 'cta') return 'CTA Sections';
    if ($n === 'faq') return 'FAQ Sections';
    if ($n === 'social proof') return 'Testimonial Sections';
    if ($n === 'process') return 'Process Sections';
    if ($n === 'services overview') return 'Feature Sections';
    if ($n === 'blog hero') return 'Blog Sections';
    if ($n === 'blog list') return 'Blog Sections';
    if ($n === 'content') return 'Content Sections';
    return 'Content Sections';
  }

  private function coerce_section_type($type, $fallback_name = '') {
    $allowed = $this->section_types();
    $type = is_string($type) ? trim($type) : '';
    if ($type !== '' && in_array($type, $allowed, true)) return $type;
    return $this->section_type_from_name($fallback_name);
  }

  public function section_types():array {
    $types = [
      'Banner Section',
      'Blog Sections',
      'Career Sections',
      'Category Filters',
      'Contact Sections',
      'Content Sections',
      'CTA Sections',
      'Event Sections',
      'FAQ Sections',
      'Feature Sections',
      'Footers',
      'Gallery Sections',
      'Headers',
      'Hero Sections',
      'Intro Sections',
      'Logo Sections',
      'Megamenu Sections - part of header section',
      'Portfolio Sections',
      'Pricing Sections',
      'Process Sections',
      'Products Sections',
      'Property Sections',
      'Single Event Sections',
      'Single Portfolio Sections',
      'Single Post Hero',
      'Single Post Sections',
      'Single Product Sections',
      'Single Property Sections',
      'Single Team Sections',
      'Team Sections',
      'Testimonial Sections',
      'Timeline Sections',
    ];

    // Allow overriding via filter, but guarantee an array return
    $filtered = apply_filters('aisb_section_types', $types);

    return is_array($filtered) ? array_values($filtered) : $types;
  }

  public function enforce_rules_on_data($data) {
    if (!is_array($data)) return $data;
    if (!isset($data['sitemap']) || !is_array($data['sitemap'])) return $data;

    $home_index = -1;
    foreach ($data['sitemap'] as $i => $p) {
      if (($p['page_type'] ?? '') === 'Home' || ($p['slug'] ?? '') === 'home') { $home_index = $i; break; }
    }
    if ($home_index === -1) {
      array_unshift($data['sitemap'], [
        'page_title' => 'Home',
        'nav_label' => 'Home',
        'slug' => 'home',
        'page_type' => 'Home',
        'priority' => 'Core',
        'parent_slug' => null,
        'sections' => $this->default_home_sections(),
        'seo' => [
          'primary_keyword' => 'home',
          'secondary_keywords' => [],
          'meta_title' => 'Home',
          'meta_description' => 'Homepage'
        ],
      ]);
      $home_index = 0;
    } else {
      $data['sitemap'][$home_index]['slug'] = 'home';
      $data['sitemap'][$home_index]['parent_slug'] = null;
      $data['sitemap'][$home_index] = $this->enforce_page_sections($data['sitemap'][$home_index], true);
    }

    foreach ($data['sitemap'] as $i => $p) {
      $slug = isset($p['slug']) ? (string)$p['slug'] : '';
      if ($slug === 'home' || (($p['page_type'] ?? '') === 'Home')) continue;
      if (!isset($data['sitemap'][$i]['parent_slug']) || $data['sitemap'][$i]['parent_slug'] === null || $data['sitemap'][$i]['parent_slug'] === '') {
        $data['sitemap'][$i]['parent_slug'] = 'home';
      }
      $data['sitemap'][$i] = $this->enforce_page_sections($data['sitemap'][$i], false);
    }

    return $data;
  }

  public function enforce_page_sections($page, $is_home = false) {
    if (!is_array($page)) return $page;
    $sections = isset($page['sections']) && is_array($page['sections']) ? $page['sections'] : [];

    $page_type = isset($page['page_type']) ? (string)$page['page_type'] : 'Other';
    $is_blog_listing = ($page_type === 'Blog') && (strpos((string)($page['slug'] ?? ''), 'post') === false);

    $sections_norm = [];
    foreach ($sections as $s) {
      if (!is_array($s)) continue;
      $name = (string)($s['section_name'] ?? ($s['name'] ?? ''));
      if (trim($name) === '') continue;

      $purpose = (string)($s['purpose'] ?? '');
      $kc = isset($s['key_content']) && is_array($s['key_content']) ? $s['key_content'] : [];
      $kc = array_values(array_filter(array_map(function($x){ return is_string($x) ? trim($x) : ''; }, $kc), function($x){ return $x !== ''; }));

      $stype = $this->coerce_section_type($s['section_type'] ?? '', $name);

      $sections_norm[] = [
        'section_name' => $name,
        'section_type' => $stype,
        'purpose' => $purpose,
        'key_content' => $kc,
      ];
    }
    $sections = $sections_norm;

    $names = [];
    foreach ($sections as $s) {
      $n = strtolower((string)($s['section_name'] ?? ''));
      if ($n !== '') $names[$n] = true;
    }

    $ensure = function($name, $purpose, $kc, $type = '') use (&$sections, &$names) {
      $key = strtolower($name);
      if (isset($names[$key])) return;
      $sections[] = [
        'section_name' => $name,
        'section_type' => $this->coerce_section_type($type, $name),
        'purpose' => $purpose,
        'key_content' => $kc,
      ];
      $names[$key] = true;
    };

    /*if ($is_blog_listing) {
      $sections = [];
      $ensure('Navbar', 'Primary navigation and key CTAs.', ['Logo', 'Menu items', 'CTA button'], 'Headers');
      $ensure('Blog Hero', 'Introduce the blog and highlight featured content.', ['Title', 'Intro text', 'Featured posts'], 'Blog Sections');
      $ensure('Blog List', 'List all blog posts with filters/search.', ['Post cards', 'Categories/tags', 'Pagination'], 'Blog Sections');
      $ensure('Footer', 'Secondary navigation, trust, and contact details.', ['Links', 'Contact info', 'Legal', 'Social links'], 'Footers');
    } else {

      $min = 5;
      $max = 10;
      $count = count($sections);
      $pad = [
        ['Social proof', 'Build trust quickly.', ['Testimonials', 'Logos', 'Ratings'], 'Testimonial Sections'],
        ['Services overview', 'Preview key offerings.', ['Service cards', 'Benefits', 'CTA to Services'], 'Feature Sections'],
        ['Process', 'Explain how it works.', ['Steps', 'Timeline', 'What to expect'], 'Process Sections'],
        ['FAQ', 'Answer common objections.', ['Pricing', 'Scope', 'Support'], 'FAQ Sections'],
        ['CTA', 'Drive the next step.', ['Call booking', 'Contact link', 'Offer summary'], 'CTA Sections'],
      ];
      foreach ($pad as $block) {
        if ($count >= $min) break;
        $ensure($block[0], $block[1], $block[2], $block[3]);
        $count = count($sections);
      }

      if (count($sections) > $max) {
        $required = ['navbar', 'hero', 'footer'];
        $req = [];
        $other = [];
        foreach ($sections as $s) {
          $n = strtolower((string)($s['section_name'] ?? ''));
          if (in_array($n, $required, true)) $req[] = $s; else $other[] = $s;
        }
        $sections = array_merge($req, array_slice($other, 0, $max - count($req)));
      }
    } */

    foreach ($sections as $idx => $s) {
      $sections[$idx]['section_type'] = $this->coerce_section_type($s['section_type'] ?? '', $s['section_name'] ?? '');
      if (!isset($sections[$idx]['purpose'])) $sections[$idx]['purpose'] = '';
      if (!isset($sections[$idx]['key_content']) || !is_array($sections[$idx]['key_content'])) $sections[$idx]['key_content'] = [];
    }

    $page['sections'] = $sections;
    return $page;
  }

  public function build_page_stub($title, $desc, $parent_slug) {
    $slug = sanitize_title($title);
    if ($slug === '') $slug = 'page-' . wp_generate_password(6, false, false);

    $page_type = 'Other';
    $t = strtolower($title);
    if (strpos($t, 'contact') !== false) $page_type = 'Contact';
    else if (strpos($t, 'about') !== false) $page_type = 'About';
    else if (strpos($t, 'service') !== false) $page_type = 'Service';
    else if (strpos($t, 'blog') !== false) $page_type = 'Blog';

    $page = [
      'page_title' => $title,
      'nav_label' => $title,
      'slug' => $slug,
      'page_type' => $page_type,
      'priority' => 'Support',
      'parent_slug' => $parent_slug ?: 'home',
      'sections' => [
        ['section_name' => 'Navbar', 'section_type' => 'Headers', 'purpose' => 'Primary navigation and key CTAs.', 'key_content' => ['Logo', 'Menu items', 'CTA button']],
        ['section_name' => 'Hero', 'section_type' => 'Hero Sections', 'purpose' => $desc ?: 'Introduce the page and its key goal.', 'key_content' => ['Headline', 'Subheadline', 'Primary CTA']],
        ['section_name' => 'Content', 'section_type' => 'Content Sections', 'purpose' => 'Explain the page topic and help the visitor.', 'key_content' => ['Key points', 'Benefits', 'Proof']],
        ['section_name' => 'FAQ', 'section_type' => 'FAQ Sections', 'purpose' => 'Answer common questions.', 'key_content' => ['Top questions', 'Clear answers']],
        ['section_name' => 'Footer', 'section_type' => 'Footers', 'purpose' => 'Secondary navigation, trust, and contact details.', 'key_content' => ['Links', 'Contact info', 'Legal']],
      ],
      'seo' => [
        'primary_keyword' => strtolower($title),
        'secondary_keywords' => [],
        'meta_title' => $title,
        'meta_description' => $desc,
      ],
    ];

    return $this->enforce_page_sections($page);
  }

  private function default_home_sections() {
    return [
      ['section_name' => 'Navbar', 'section_type' => 'Headers', 'purpose' => 'Primary navigation and key CTAs.', 'key_content' => ['Logo', 'Menu items', 'CTA button']],
      ['section_name' => 'Hero', 'section_type' => 'Hero Sections', 'purpose' => 'Explain what you do and for whom.', 'key_content' => ['Headline', 'Subheadline', 'Primary CTA']],
      ['section_name' => 'Services overview', 'section_type' => 'Feature Sections', 'purpose' => 'Preview key services.', 'key_content' => ['Service cards', 'Benefits', 'CTA to Services']],
      ['section_name' => 'Social proof', 'section_type' => 'Testimonial Sections', 'purpose' => 'Build trust quickly.', 'key_content' => ['Testimonials', 'Logos', 'Ratings']],
      ['section_name' => 'Process', 'section_type' => 'Process Sections', 'purpose' => 'Reduce uncertainty.', 'key_content' => ['Steps', 'Timeline', 'What to expect']],
      ['section_name' => 'FAQ', 'section_type' => 'FAQ Sections', 'purpose' => 'Handle objections.', 'key_content' => ['Pricing', 'Scope', 'Support']],
      ['section_name' => 'CTA', 'section_type' => 'CTA Sections', 'purpose' => 'Convert visitors.', 'key_content' => ['Book a call', 'Contact link']],
      ['section_name' => 'Footer', 'section_type' => 'Footers', 'purpose' => 'Secondary navigation, trust, and contact details.', 'key_content' => ['Links', 'Contact info', 'Legal', 'Social']],
    ];
  }
}