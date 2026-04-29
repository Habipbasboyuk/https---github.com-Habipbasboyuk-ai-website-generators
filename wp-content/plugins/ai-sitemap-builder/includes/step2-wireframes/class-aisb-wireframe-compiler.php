<?php

if (!defined('ABSPATH')) exit;

class AISB_Wireframe_Compiler {

  /** @var AISB_Template_Library */
  private $tpl_lib;

  public function __construct(AISB_Template_Library $tpl_lib) {
    $this->tpl_lib = $tpl_lib;
  }

  /**
   * Compile a wireframe model (page + sections) into a single Bricks JSON payload.
   * Decision: we strip `code` nodes during compilation.
   */
  public function compile_page(array $wireframe_model): array {
    $sections = isset($wireframe_model['sections']) && is_array($wireframe_model['sections']) ? $wireframe_model['sections'] : [];

    $final_content = [];
    $final_global_classes = [];

    foreach ($sections as $sec) {
      if (!is_array($sec)) continue;

      // Header/footer secties laten we origineel — geen extra padding overrides.
      $sec_type = strtolower((string)($sec['type'] ?? ''));
      $skip_padding = in_array($sec_type, ['header', 'footer'], true);

      // --- Prioriteit A: AI wireframe post (ai_wireframe CPT met AI-gegenereerde Bricks elementen) ---
      $ai_wireframe_id = isset($sec['ai_wireframe_id']) ? (int) $sec['ai_wireframe_id'] : 0;
      if ($ai_wireframe_id > 0) {
        // Bricks elementen ophalen uit de ai_wireframe post
        $ai_data = get_post_meta($ai_wireframe_id, '_bricks_page_content_2', true);
        if (is_array($ai_data) && !empty($ai_data)) {
          $tpl_content = array_values(array_filter($ai_data, function($n) {
            return is_array($n) && (($n['name'] ?? '') !== 'code');
          }));
          $rekeyed = $this->re_id_bricks_nodes($tpl_content);
          $rekeyed = $this->force_root_parent_zero($rekeyed);
          $rekeyed = $this->neutralize_accordion_query_loops($rekeyed);
          if (!$skip_padding) $rekeyed = $this->apply_section_padding($rekeyed);
          $final_content = array_merge($final_content, $rekeyed);
          continue;
        }
      }

      // --- Prioriteit B: Bricks template post (uit de Bricks template bibliotheek) ---
      $bricks_post_id = isset($sec['bricks_template_id']) ? (int) $sec['bricks_template_id'] : 0;
      if ($bricks_post_id > 0) {
        $bricks_data = get_post_meta($bricks_post_id, '_bricks_data', true);
        if (is_array($bricks_data) && !empty($bricks_data)) {
          $tpl_content = array_values(array_filter($bricks_data, function($n) {
            return is_array($n) && (($n['name'] ?? '') !== 'code');
          }));
          $rekeyed = $this->re_id_bricks_nodes($tpl_content);
          $rekeyed = $this->force_root_parent_zero($rekeyed);
          $rekeyed = $this->neutralize_accordion_query_loops($rekeyed);
          if (!$skip_padding) $rekeyed = $this->apply_section_padding($rekeyed);
          $final_content = array_merge($final_content, $rekeyed);
          continue;
        }
        // Bricks template post exists but has no _bricks_data — skip gracefully.
        continue;
      }

      // --- Fallback: custom aisb_section_templates library ---
      $layout_key = (string) ($sec['layout_key'] ?? '');
      if ($layout_key === '') continue;

      $tpl = $this->tpl_lib->get_template_by_layout_key($layout_key);
      if (!$tpl || empty($tpl['bricks_json'])) continue;

      $decoded = json_decode((string) $tpl['bricks_json'], true);
      if (!is_array($decoded)) continue;

      $tpl_content = isset($decoded['content']) && is_array($decoded['content']) ? $decoded['content'] : [];
      // Strip code nodes
      $tpl_content = array_values(array_filter($tpl_content, function($n) {
        return is_array($n) && (($n['name'] ?? '') !== 'code');
      }));

      // Re-ID all nodes and fix relationships
      $rekeyed = $this->re_id_bricks_nodes($tpl_content);
      // Ensure root section parent is 0
      $rekeyed = $this->force_root_parent_zero($rekeyed);
      $rekeyed = $this->neutralize_accordion_query_loops($rekeyed);
      if (!$skip_padding) $rekeyed = $this->apply_section_padding($rekeyed);

      // Merge
      $final_content = array_merge($final_content, $rekeyed);

      // Merge globalClasses if present
      if (isset($decoded['globalClasses']) && is_array($decoded['globalClasses'])) {
        $final_global_classes = $this->merge_global_classes($final_global_classes, $decoded['globalClasses']);
      }
    }

    $out = [
      'content' => $final_content,
    ];
    if (!empty($final_global_classes)) {
      $out['globalClasses'] = array_values($final_global_classes);
    }
    return $out;
  }

  /**
   * @param array<int,array> $nodes
   * @return array<int,array>
   */
  private function re_id_bricks_nodes(array $nodes): array {
    $map = [];
    foreach ($nodes as $n) {
      if (!is_array($n)) continue;
      $old = (string)($n['id'] ?? '');
      if ($old === '') continue;
      $map[$old] = $this->new_bricks_id();
    }

    $out = [];
    foreach ($nodes as $n) {
      if (!is_array($n)) continue;
      $old_id = (string)($n['id'] ?? '');
      if ($old_id === '' || !isset($map[$old_id])) continue;

      $n['id'] = $map[$old_id];

      // parent
      if (isset($n['parent']) && $n['parent'] !== 0 && $n['parent'] !== '0') {
        $old_parent = (string)$n['parent'];
        if (isset($map[$old_parent])) $n['parent'] = $map[$old_parent];
      }
      // children
      if (isset($n['children']) && is_array($n['children'])) {
        $n['children'] = array_values(array_filter(array_map(function($cid) use ($map){
          $cid = (string)$cid;
          return isset($map[$cid]) ? $map[$cid] : null;
        }, $n['children']), function($x){ return $x !== null; }));
      }

      $out[] = $n;
    }

    return $out;
  }

  private function new_bricks_id(): string {
    // Bricks IDs are short strings; 6 chars is fine.
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $id = '';
    for ($i=0; $i<6; $i++) {
      $id .= $chars[random_int(0, strlen($chars)-1)];
    }
    return $id;
  }

  /**
   * Force first section node (name=section) to be top-level.
   */
  private function force_root_parent_zero(array $nodes): array {
    foreach ($nodes as $i => $n) {
      if (!is_array($n)) continue;
      if (($n['name'] ?? '') === 'section') {
        $nodes[$i]['parent'] = 0;
        break;
      }
    }
    return $nodes;
  }

  /**
   * Forceer ruime top/bottom padding op root <section> nodes (Relume-style spacing).
   * Overschrijft bestaande top/bottom — horizontale paddings uit het template
   * blijven behouden.
   */
  private function apply_section_padding(array $nodes): array {
    $top    = '3rem';
    $bottom = '3rem';

    foreach ($nodes as $i => $n) {
      if (!is_array($n)) continue;
      $is_root_wrapper = in_array(($n['name'] ?? ''), ['section', 'container', 'block'], true)
                         && (int)($n['parent'] ?? 0) === 0;
      if (!$is_root_wrapper) continue;

      $settings = isset($n['settings']) && is_array($n['settings']) ? $n['settings'] : [];
      $existing = isset($settings['_padding']) && is_array($settings['_padding']) ? $settings['_padding'] : [];

      // Forceer top/bottom — laat horizontale waarden ongemoeid.
      $existing['top']    = $top;
      $existing['bottom'] = $bottom;
      if (!isset($existing['left']))  $existing['left']   = '';
      if (!isset($existing['right'])) $existing['right']  = '';

      $settings['_padding'] = $existing;

      // Responsive overrides die hetzelfde mechanisme gebruiken (Bricks slaat
      // breakpoint-overrides op als _padding:tablet_portrait, :mobile_portrait etc.).
      // Verwijder deze zodat onze waarde niet alsnog door een breakpoint
      // overschreven wordt.
      foreach (array_keys($settings) as $k) {
        if (is_string($k) && strpos($k, '_padding:') === 0) {
          unset($settings[$k]);
        }
      }

      $nodes[$i]['settings'] = $settings;
    }
    return $nodes;
  }

  /**
   * FAQ-templates gebruiken vaak een Bricks Query Loop binnen een accordion-nested:
   * één child-block met een `query` setting dat in de frontend over een (vaak lege)
   * CPT loopt, waardoor de AI-gevulde placeholder-tekst onzichtbaar blijft.
   * We strippen de loop op blocks die directe kinderen zijn van een accordion-nested,
   * zodat het template één keer statisch rendert mét de AI-tekst.
   */
  private function neutralize_accordion_query_loops(array $nodes): array {
    // Verzamel ID's van accordion-nested elementen.
    $accordion_ids = [];
    foreach ($nodes as $n) {
      if (!is_array($n)) continue;
      if (($n['name'] ?? '') === 'accordion-nested') {
        $id = (string)($n['id'] ?? '');
        if ($id !== '') $accordion_ids[$id] = true;
      }
    }
    if (!$accordion_ids) return $nodes;

    foreach ($nodes as $i => $n) {
      if (!is_array($n)) continue;
      $parent = (string)($n['parent'] ?? '');
      if (!isset($accordion_ids[$parent])) continue;
      if (!isset($n['settings']) || !is_array($n['settings'])) continue;
      if (!isset($n['settings']['query'])) continue;

      // Verwijder de query-loop zodat het block zich gedraagt als statische container.
      unset($nodes[$i]['settings']['query']);
      if (isset($nodes[$i]['settings']['hasLoop']))   unset($nodes[$i]['settings']['hasLoop']);
      if (isset($nodes[$i]['settings']['_query']))    unset($nodes[$i]['settings']['_query']);
    }
    return array_values($nodes);
  }

  /**
   * @param array<int,array> $acc
   * @param array<int,array> $incoming
   * @return array<int,array>
   */
  private function merge_global_classes(array $acc, array $incoming): array {
    // Dedupe by class name OR id if present.
    $seen = [];
    foreach ($acc as $c) {
      if (!is_array($c)) continue;
      $key = (string)($c['name'] ?? ($c['id'] ?? ''));
      if ($key !== '') $seen[$key] = true;
    }
    foreach ($incoming as $c) {
      if (!is_array($c)) continue;
      
      // Fix undefined array key 'name' issues in Bricks: Force a name if missing
      if (!isset($c['name'])) {
        $c['name'] = $c['id'] ?? 'aisb-class-' . uniqid();
      }

      $key = (string)($c['name'] ?? ($c['id'] ?? ''));
      if ($key === '' || isset($seen[$key])) continue;
      
      $acc[] = $c;
      $seen[$key] = true;
    }
    return $acc;
  }
}
