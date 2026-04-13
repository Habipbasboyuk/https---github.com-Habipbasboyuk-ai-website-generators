<?php
namespace UiXpress\Rest\CustomFields;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class FieldGroupRepository
 *
 * Handles JSON file operations and CRUD operations for field groups
 */
class FieldGroupRepository
{
  /**
   * Path to the JSON storage file
   *
   * @var string
   */
  private $json_file_path;

  /**
   * Initialize the repository
   *
   * @param string $json_file_path Path to the JSON file
   */
  public function __construct($json_file_path)
  {
    $this->json_file_path = $json_file_path;
  }

  /**
   * Read field groups from JSON file
   *
   * @return array Array of field groups
   */
  public function read()
  {
    if (!file_exists($this->json_file_path)) {
      return [];
    }

    $json_content = file_get_contents($this->json_file_path);
    if ($json_content === false) {
      return [];
    }

    $data = json_decode($json_content, true);
    return is_array($data) ? $data : [];
  }

  /**
   * Write field groups to JSON file
   *
   * @param array $field_groups Array of field groups
   * @return bool True on success, false on failure
   */
  public function write($field_groups)
  {
    $json_content = wp_json_encode($field_groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // Ensure directory exists
    $dir = dirname($this->json_file_path);
    if (!file_exists($dir)) {
      wp_mkdir_p($dir);
    }

    $result = file_put_contents($this->json_file_path, $json_content);
    
    // Clear any caches
    if (function_exists('wp_cache_flush')) {
      wp_cache_flush();
    }
    
    // Fire action to clear field definition cache for helper functions
    if ($result !== false) {
      /**
       * Fires after field groups are saved
       *
       * Used to clear cached field definitions in helper functions.
       *
       * @since 1.3.0
       * @param array $field_groups The saved field groups
       */
      do_action('uixpress_field_groups_saved', $field_groups);
    }
    
    return $result !== false;
  }

  /**
   * Generate unique ID for field group
   *
   * @return string Unique ID
   */
  public function generate_id()
  {
    return 'group_' . substr(md5(uniqid(mt_rand(), true)), 0, 12);
  }

  /**
   * Generate unique ID for field
   *
   * @return string Unique ID
   */
  public function generate_field_id()
  {
    return 'field_' . substr(md5(uniqid(mt_rand(), true)), 0, 12);
  }

  /**
   * Check if ID exists in field groups array
   *
   * @param string $id The ID to check
   * @param array $field_groups Array of field groups
   * @return bool True if exists, false otherwise
   */
  public function id_exists($id, $field_groups)
  {
    foreach ($field_groups as $group) {
      if ($group['id'] === $id) {
        return true;
      }
    }
    return false;
  }

  /**
   * Find field group by ID
   *
   * @param string $id Field group ID
   * @return array|null Field group or null if not found
   */
  public function find_by_id($id)
  {
    $field_groups = $this->read();
    
    foreach ($field_groups as $group) {
      if ($group['id'] === $id) {
        return $group;
      }
    }
    
    return null;
  }

  /**
   * Find field group index by ID
   *
   * @param string $id Field group ID
   * @return int Index or -1 if not found
   */
  public function find_index_by_id($id)
  {
    $field_groups = $this->read();
    
    foreach ($field_groups as $index => $group) {
      if ($group['id'] === $id) {
        return $index;
      }
    }
    
    return -1;
  }

  /**
   * Check if we have active field groups
   *
   * @return bool True if active groups exist
   */
  public function has_active_groups()
  {
    $field_groups = $this->read();
    
    if (empty($field_groups)) {
      return false;
    }

    foreach ($field_groups as $group) {
      if (!empty($group['active'])) {
        return true;
      }
    }

    return false;
  }

  /**
   * Get all active field groups
   *
   * @return array Array of active field groups
   */
  public function get_active_groups()
  {
    $field_groups = $this->read();
    $active = [];
    
    foreach ($field_groups as $group) {
      if (!empty($group['active'])) {
        $active[] = $group;
      }
    }
    
    return $active;
  }
}

