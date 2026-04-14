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

      // --- Primary: Bricks template post (from the live Bricks library) ---
      $bricks_post_id = isset($sec['bricks_template_id']) ? (int) $sec['bricks_template_id'] : 0;
      if ($bricks_post_id > 0) {
        $bricks_data = get_post_meta($bricks_post_id, '_bricks_data', true);
        if (is_array($bricks_data) && !empty($bricks_data)) {
          $tpl_content = array_values(array_filter($bricks_data, function($n) {
            return is_array($n) && (($n['name'] ?? '') !== 'code');
          }));
          $rekeyed = $this->re_id_bricks_nodes($tpl_content);
          $rekeyed = $this->force_root_parent_zero($rekeyed);
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
