<?php

if (!defined('ABSPATH')) exit;

/**
 * Parses Bricks "copied elements" JSON and derives a lightweight schema
 * for fast wireframe preview rendering.
 */
class AISB_Template_Analyzer {

  /**
   * Increment when the preview schema structure changes.
   * Used to regenerate older/missing preview schemas.
   */
  const PREVIEW_SCHEMA_VERSION = 1;

  /**
   * @param array $decoded Decoded JSON (associative)
   * @return array{section_type:string,preview_schema:array,signature:string,complexity_score:int}
   */
  public function analyze(array $decoded): array {
    $content = isset($decoded['content']) && is_array($decoded['content']) ? $decoded['content'] : [];

    $root = $this->find_root_section($content);
    $label = is_array($root) && isset($root['label']) ? (string)$root['label'] : '';
    $section_type = $this->normalize_section_type($label);

    $stats = [
      'nodes' => 0,
      'headings' => 0,
      'text' => 0,
      'buttons' => 0,
      'images' => 0,
      'videos' => 0,
      'tabs' => 0,
      'ul_lists' => 0,
      'blockquotes' => 0,
      'cards_guess' => 0,
    ];

    $this->walk_nodes($content, function(array $node) use (&$stats) {
      $stats['nodes']++;
      $name = (string)($node['name'] ?? '');
      if ($name === 'heading') $stats['headings']++;
      if ($name === 'text-basic') $stats['text']++;
      if ($name === 'button') $stats['buttons']++;
      if ($name === 'image') $stats['images']++;
      if ($name === 'video') $stats['videos']++;
      if ($name === 'tabs-nested') $stats['tabs']++;

      $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
      $tag = (string)($settings['tag'] ?? '');
      if (strtolower($tag) === 'ul') $stats['ul_lists']++;

      $custom_tag = (string)($settings['customTag'] ?? '');
      if (strtolower($custom_tag) === 'blockquote') $stats['blockquotes']++;

      // Heuristic: containers/grids/repeaters often translate to cards
      if (in_array($name, ['block', 'container', 'div', 'section'], true)) {
        $children = $node['children'] ?? [];
        if (is_array($children) && count($children) >= 3) {
          $stats['cards_guess'] = max($stats['cards_guess'], count($children));
        }
      }
    });

    $flags = [
      'has_media' => ($stats['images'] + $stats['videos']) > 0,
      'has_tabs' => $stats['tabs'] > 0,
      'has_list' => $stats['ul_lists'] > 0,
      'is_testimonialish' => $stats['blockquotes'] > 0 || stripos($label, 'testimonial') !== false,
    ];

    $preview_schema = $this->optimize_preview_schema([
      'schema_version' => self::PREVIEW_SCHEMA_VERSION,
      'section_type' => $section_type,
      'layout_kind' => $this->derive_layout_kind($section_type, $flags, $stats),
      'slot_counts' => [
        'headings' => $stats['headings'],
        'text' => $stats['text'],
        'buttons' => $stats['buttons'],
        'media' => ($stats['images'] + $stats['videos']),
        'cards' => $stats['cards_guess'] ?: 3,
      ],
      'flags' => $flags,
      // Convenience fields for the renderer (avoid recomputing on every request)
      'signature' => '',
      'complexity_score' => 0,
    ]);

    $signature_parts = [
      $section_type,
      $flags['has_media'] ? 'm1' : 'm0',
      $flags['has_tabs'] ? 't1' : 't0',
      $flags['has_list'] ? 'l1' : 'l0',
      $flags['is_testimonialish'] ? 'q1' : 'q0',
      'b' . (int)$stats['buttons'],
      'h' . (int)$stats['headings'],
      'c' . (int)($stats['cards_guess'] ?: 3),
    ];
    $signature = implode('|', $signature_parts);

    // Complexity: node count + media + tabs weighted
    $complexity = (int)$stats['nodes']
      + (int)($stats['images'] * 5)
      + (int)($stats['videos'] * 8)
      + (int)($stats['tabs'] * 10);

    // Attach derived values so the frontend can sort/filter without extra math.
    $preview_schema['signature'] = $signature;
    $preview_schema['complexity_score'] = $complexity;

    return [
      'section_type' => $section_type,
      'preview_schema' => $preview_schema,
      'signature' => $signature,
      'complexity_score' => $complexity,
    ];
  }

  /**
   * Keep the schema small, stable and safe to store as post meta.
   * - Casts types
   * - Caps large numbers (avoid bloating meta for unusual templates)
   */
  private function optimize_preview_schema(array $schema): array {
    $schema['schema_version'] = (int)($schema['schema_version'] ?? self::PREVIEW_SCHEMA_VERSION);
    $schema['section_type'] = (string)($schema['section_type'] ?? 'generic');
    $schema['layout_kind'] = (string)($schema['layout_kind'] ?? 'generic_simple');

    $slot_counts = isset($schema['slot_counts']) && is_array($schema['slot_counts']) ? $schema['slot_counts'] : [];
    foreach (['headings','text','buttons','media','cards'] as $k) {
      $v = (int)($slot_counts[$k] ?? 0);
      // Reasonable caps for preview rendering.
      if ($k === 'cards') $v = max(1, min(12, $v));
      else $v = max(0, min(20, $v));
      $slot_counts[$k] = $v;
    }
    $schema['slot_counts'] = $slot_counts;

    $flags = isset($schema['flags']) && is_array($schema['flags']) ? $schema['flags'] : [];
    foreach (['has_media','has_tabs','has_list','is_testimonialish'] as $k) {
      $flags[$k] = !empty($flags[$k]);
    }
    $schema['flags'] = $flags;

    $schema['signature'] = (string)($schema['signature'] ?? '');
    $schema['complexity_score'] = (int)($schema['complexity_score'] ?? 0);

    return $schema;
  }

  /** @return array|null */
  private function find_root_section(array $content) {
    foreach ($content as $node) {
      if (!is_array($node)) continue;
      if (($node['name'] ?? '') === 'section') return $node;
    }
    return null;
  }

  private function normalize_section_type(string $label): string {
    $label = trim($label);
    if ($label === '') return 'generic';
    $label = strtolower($label);
    // Normalize common Brixies labels
    $map = [
      'hero' => 'hero',
      'features' => 'features',
      'feature' => 'features',
      'cta' => 'cta',
      'faq' => 'faq',
      'testimonials' => 'testimonials',
      'testimonial' => 'testimonials',
      'pricing' => 'pricing',
      'footer' => 'footer',
      'header' => 'header',
      'team' => 'team',
      'contact' => 'contact_form',
      'process' => 'process',
      'logos' => 'social_proof',
      'logo' => 'social_proof',
    ];
    foreach ($map as $needle => $type) {
      if (strpos($label, $needle) !== false) return $type;
    }
    // Fallback: slugify label
    $label = preg_replace('/[^a-z0-9]+/', '_', $label);
    $label = trim((string)$label, '_');
    return $label ?: 'generic';
  }

  private function derive_layout_kind(string $section_type, array $flags, array $stats): string {
    if ($section_type === 'hero') {
      if ($flags['has_media'] && ($stats['cards_guess'] ?? 0) >= 4) return 'hero_media_mosaic';
      if ($flags['has_media']) return 'hero_media_split';
      return 'hero_text_centered';
    }
    if ($flags['is_testimonialish']) return 'testimonials';
    if ($flags['has_tabs']) return $section_type . '_tabs';
    if (($stats['cards_guess'] ?? 0) >= 4) return $section_type . '_grid';
    return $section_type . '_simple';
  }

  /**
   * Walk Bricks copied-elements tree.
   * The JSON is usually a flat list with parent/children relationships.
   */
  private function walk_nodes(array $content, callable $fn): void {
    foreach ($content as $node) {
      if (!is_array($node)) continue;
      $fn($node);
    }
  }
}
