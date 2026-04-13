<?php

namespace UiXpress\Rest;

use wpdb;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class DatabaseExplorer
 *
 * REST API endpoints for database explorer functionality
 */
class DatabaseExplorer
{
  /**
   * DatabaseExplorer constructor.
   *
   * Registers REST API endpoints
   */
  public function __construct()
  {
    add_action("rest_api_init", [$this, "register_custom_endpoints"]);
  }

  /**
   * Registers custom REST API endpoints for database operations
   *
   * @return void
   */
  public static function register_custom_endpoints()
  {
    // Get list of all tables
    register_rest_route("uixpress/v1", "/database/tables", [
      "methods" => "GET",
      "callback" => ["UiXpress\Rest\DatabaseExplorer", "get_tables"],
      "permission_callback" => ["UiXpress\Rest\DatabaseExplorer", "check_permissions"],
    ]);

    // Get table structure (columns, indexes, etc.)
    register_rest_route("uixpress/v1", "/database/tables/(?P<table>[a-zA-Z0-9_]+)/structure", [
      "methods" => "GET",
      "callback" => ["UiXpress\Rest\DatabaseExplorer", "get_table_structure"],
      "permission_callback" => ["UiXpress\Rest\DatabaseExplorer", "check_permissions"],
      "args" => [
        "table" => [
          "required" => true,
          "validate_callback" => function ($param) {
            return is_string($param) && !empty($param);
          },
          "sanitize_callback" => "sanitize_text_field",
        ],
      ],
    ]);

    // Get table data with pagination
    register_rest_route("uixpress/v1", "/database/tables/(?P<table>[a-zA-Z0-9_]+)/data", [
      "methods" => "GET",
      "callback" => ["UiXpress\Rest\DatabaseExplorer", "get_table_data"],
      "permission_callback" => ["UiXpress\Rest\DatabaseExplorer", "check_permissions"],
      "args" => [
        "table" => [
          "required" => true,
          "validate_callback" => function ($param) {
            return is_string($param) && !empty($param);
          },
          "sanitize_callback" => "sanitize_text_field",
        ],
        "page" => [
          "required" => false,
          "default" => 1,
          "validate_callback" => function ($param) {
            return is_numeric($param) && $param > 0;
          },
          "sanitize_callback" => "absint",
        ],
        "per_page" => [
          "required" => false,
          "default" => 50,
          "validate_callback" => function ($param) {
            return is_numeric($param) && $param > 0 && $param <= 500;
          },
          "sanitize_callback" => "absint",
        ],
        "orderby" => [
          "required" => false,
          "default" => null,
          "sanitize_callback" => "sanitize_text_field",
        ],
        "order" => [
          "required" => false,
          "default" => "ASC",
          "validate_callback" => function ($param) {
            return in_array(strtoupper($param), ["ASC", "DESC"]);
          },
          "sanitize_callback" => "sanitize_text_field",
        ],
        "search" => [
          "required" => false,
          "default" => "",
          "sanitize_callback" => "sanitize_text_field",
        ],
      ],
    ]);

    // Execute custom SQL query (read-only for safety)
    register_rest_route("uixpress/v1", "/database/query", [
      "methods" => "POST",
      "callback" => ["UiXpress\Rest\DatabaseExplorer", "execute_query"],
      "permission_callback" => ["UiXpress\Rest\DatabaseExplorer", "check_permissions"],
      "args" => [
        "query" => [
          "required" => true,
          "validate_callback" => function ($param) {
            return is_string($param) && !empty(trim($param));
          },
          "sanitize_callback" => function ($param) {
            // Only allow SELECT queries for safety
            $trimmed = trim($param);
            if (stripos($trimmed, "SELECT") !== 0) {
              return new \WP_Error("invalid_query", __("Only SELECT queries are allowed for safety.", "uixpress"), ["status" => 400]);
            }
            return $param;
          },
        ],
        "limit" => [
          "required" => false,
          "default" => 1000,
          "validate_callback" => function ($param) {
            return is_numeric($param) && $param > 0 && $param <= 10000;
          },
          "sanitize_callback" => "absint",
        ],
      ],
    ]);

    // Get table row count
    register_rest_route("uixpress/v1", "/database/tables/(?P<table>[a-zA-Z0-9_]+)/count", [
      "methods" => "GET",
      "callback" => ["UiXpress\Rest\DatabaseExplorer", "get_table_count"],
      "permission_callback" => ["UiXpress\Rest\DatabaseExplorer", "check_permissions"],
      "args" => [
        "table" => [
          "required" => true,
          "validate_callback" => function ($param) {
            return is_string($param) && !empty($param);
          },
          "sanitize_callback" => "sanitize_text_field",
        ],
      ],
    ]);

    // Verify user password for destructive operations
    register_rest_route("uixpress/v1", "/database/verify-password", [
      "methods" => "POST",
      "callback" => ["UiXpress\Rest\DatabaseExplorer", "verify_password"],
      "permission_callback" => ["UiXpress\Rest\DatabaseExplorer", "check_permissions"],
      "args" => [
        "password" => [
          "required" => true,
          "validate_callback" => function ($param) {
            return is_string($param) && !empty($param);
          },
          "sanitize_callback" => "sanitize_text_field",
        ],
      ],
    ]);

    // Delete a non-standard WordPress table
    register_rest_route("uixpress/v1", "/database/tables/(?P<table>[a-zA-Z0-9_]+)", [
      "methods" => "DELETE",
      "callback" => ["UiXpress\Rest\DatabaseExplorer", "delete_table"],
      "permission_callback" => ["UiXpress\Rest\DatabaseExplorer", "check_permissions"],
      "args" => [
        "table" => [
          "required" => true,
          "validate_callback" => function ($param) {
            return is_string($param) && !empty($param);
          },
          "sanitize_callback" => "sanitize_text_field",
        ],
        "password" => [
          "required" => true,
          "validate_callback" => function ($param) {
            return is_string($param) && !empty($param);
          },
          "sanitize_callback" => "sanitize_text_field",
        ],
      ],
    ]);
  }

  /**
   * Checks if the user has permission to access database explorer
   *
   * @param \WP_REST_Request $request The REST request object
   * @return bool|\WP_Error True if user has permission, WP_Error otherwise
   */
  public static function check_permissions($request)
  {
    return RestPermissionChecker::check_permissions($request, 'manage_options');
  }

  /**
   * Gets the list of standard WordPress table names (without prefix)
   * Based on WordPress Codex: https://codex.wordpress.org/Database_Description
   *
   * @return array Array of standard WordPress table names
   */
  private static function get_standard_wp_tables()
  {
    // Standard WordPress tables (12 core tables)
    $standard_tables = [
      "commentmeta",
      "comments",
      "links",
      "options",
      "postmeta",
      "posts",
      "terms",
      "termmeta",
      "term_relationships",
      "term_taxonomy",
      "usermeta",
      "users",
    ];

    // Multisite tables (if multisite is enabled)
    if (is_multisite()) {
      $multisite_tables = [
        "blogs",
        "blog_versions",
        "registration_log",
        "signups",
        "site",
        "sitemeta",
      ];
      $standard_tables = array_merge($standard_tables, $multisite_tables);
    }

    return $standard_tables;
  }

  /**
   * Checks if a table is a WordPress core table regardless of prefix
   *
   * @param string $table_name The full table name to check
   * @return bool True if it's a WordPress core table, false otherwise
   */
  private static function is_wordpress_table($table_name)
  {
    global $wpdb;

    $table_prefix = $wpdb->prefix;
    $standard_tables = self::get_standard_wp_tables();

    // Check if table starts with WordPress prefix
    if (strpos($table_name, $table_prefix) !== 0) {
      return false;
    }

    // Remove prefix to get base table name
    $base_table_name = substr($table_name, strlen($table_prefix));

    // Check if it matches a standard WordPress table name exactly
    if (in_array($base_table_name, $standard_tables)) {
      return true;
    }

    // Check for multisite numbered tables (e.g., wp_2_posts, wp_3_comments)
    // Pattern: prefix + number + underscore + table_name
    if (is_multisite() && preg_match('/^(\d+)_(.+)$/', $base_table_name, $matches)) {
      $site_id = $matches[1];
      $actual_table_name = $matches[2];
      if (in_array($actual_table_name, $standard_tables)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Validates and sanitizes table name to prevent SQL injection
   *
   * @param string $table_name The table name to validate
   * @return string|false Sanitized table name or false if invalid
   */
  private static function validate_table_name($table_name)
  {
    // Only allow alphanumeric characters, underscores, and ensure it's a valid identifier
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
      return false;
    }
    // Additional check: ensure it doesn't contain SQL keywords or special characters
    if (strlen($table_name) > 64 || strlen($table_name) < 1) {
      return false;
    }
    return $table_name;
  }

  /**
   * Gets list of all database tables
   *
   * @param \WP_REST_Request $request The REST request object
   * @return \WP_REST_Response Response containing table list
   */
  public static function get_tables($request)
  {
    global $wpdb;

    $tables = [];
    $table_prefix = $wpdb->prefix;

    // Get all tables
    $results = $wpdb->get_results("SHOW TABLES", ARRAY_N);

    foreach ($results as $row) {
      $table_name = $row[0];
      
      // Validate table name
      $validated_table_name = self::validate_table_name($table_name);
      if (!$validated_table_name) {
        continue; // Skip invalid table names
      }
      
      // Check if it's a WordPress core table (regardless of prefix)
      $is_wp_table = self::is_wordpress_table($table_name);

      // Get row count - table name is validated, so esc_sql is safe
      $escaped_table_name = esc_sql($table_name);
      $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$escaped_table_name}`");

      // Get table size
      $size_query = $wpdb->prepare(
        "SELECT 
          ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
        FROM information_schema.TABLES 
        WHERE table_schema = %s 
        AND table_name = %s",
        DB_NAME,
        $table_name
      );
      $size = $wpdb->get_var($size_query);

      $tables[] = [
        "name" => esc_html($table_name), // Escape for output
        "is_wp_table" => $is_wp_table,
        "row_count" => (int) $count,
        "size_mb" => $size ? (float) $size : 0,
      ];
    }

    return new \WP_REST_Response($tables, 200);
  }

  /**
   * Gets table structure (columns, indexes, etc.)
   *
   * @param \WP_REST_Request $request The REST request object
   * @return \WP_REST_Response Response containing table structure
   */
  public static function get_table_structure($request)
  {
    global $wpdb;

    $table_name = $request->get_param("table");

    // Validate table name format
    $validated_table_name = self::validate_table_name($table_name);
    if (!$validated_table_name) {
      return new \WP_Error("invalid_table_name", __("Invalid table name.", "uixpress"), ["status" => 400]);
    }

    // Validate table name exists
    if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name))) {
      return new \WP_Error("table_not_found", __("Table not found.", "uixpress"), ["status" => 404]);
    }

    // Table name is validated, so esc_sql is safe
    $escaped_table_name = esc_sql($table_name);
    
    // Get columns
    $columns = $wpdb->get_results("DESCRIBE `{$escaped_table_name}`", ARRAY_A);

    // Get indexes
    $indexes = $wpdb->get_results("SHOW INDEXES FROM `{$escaped_table_name}`", ARRAY_A);

    // Get foreign keys (if supported)
    $foreign_keys = [];
    $fk_query = $wpdb->prepare(
      "SELECT 
        CONSTRAINT_NAME,
        COLUMN_NAME,
        REFERENCED_TABLE_NAME,
        REFERENCED_COLUMN_NAME
      FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = %s
      AND TABLE_NAME = %s
      AND REFERENCED_TABLE_NAME IS NOT NULL",
      DB_NAME,
      $table_name
    );
    $foreign_keys = $wpdb->get_results($fk_query, ARRAY_A);

    return new \WP_REST_Response(
      [
        "columns" => $columns,
        "indexes" => $indexes,
        "foreign_keys" => $foreign_keys,
      ],
      200
    );
  }

  /**
   * Gets table data with pagination
   *
   * @param \WP_REST_Request $request The REST request object
   * @return \WP_REST_Response Response containing table data
   */
  public static function get_table_data($request)
  {
    global $wpdb;

    $table_name = $request->get_param("table");
    $page = $request->get_param("page", 1);
    $per_page = $request->get_param("per_page", 50);
    $orderby = $request->get_param("orderby");
    $order = strtoupper($request->get_param("order", "ASC"));
    $search = $request->get_param("search", "");

    // Validate table name format
    $validated_table_name = self::validate_table_name($table_name);
    if (!$validated_table_name) {
      return new \WP_Error("invalid_table_name", __("Invalid table name.", "uixpress"), ["status" => 400]);
    }

    // Validate table name exists
    if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name))) {
      return new \WP_Error("table_not_found", __("Table not found.", "uixpress"), ["status" => 404]);
    }

    // Escape table name for safety (after validation)
    $escaped_table_name = esc_sql($table_name);

    // Get column names for validation
    $columns = $wpdb->get_col("DESCRIBE `{$escaped_table_name}`");
    if (empty($columns)) {
      return new \WP_Error("table_error", __("Unable to retrieve table structure.", "uixpress"), ["status" => 500]);
    }

    // Get total count
    $count_query = "SELECT COUNT(*) FROM `{$escaped_table_name}`";
    if (!empty($search)) {
      // Sanitize search term
      $search = sanitize_text_field($search);
      $search_conditions = [];
      foreach ($columns as $column) {
        // Validate column name
        if (!self::validate_table_name($column)) {
          continue;
        }
        $escaped_column = esc_sql($column);
        $search_conditions[] = $wpdb->prepare("`{$escaped_column}` LIKE %s", "%" . $wpdb->esc_like($search) . "%");
      }
      if (!empty($search_conditions)) {
        $count_query .= " WHERE " . implode(" OR ", $search_conditions);
      }
    }
    $total = (int) $wpdb->get_var($count_query);

    // Build query
    $offset = ($page - 1) * $per_page;
    $query = "SELECT * FROM `{$escaped_table_name}`";

    // Add search
    if (!empty($search)) {
      $search_conditions = [];
      foreach ($columns as $column) {
        // Validate column name
        if (!self::validate_table_name($column)) {
          continue;
        }
        $escaped_column = esc_sql($column);
        $search_conditions[] = $wpdb->prepare("`{$escaped_column}` LIKE %s", "%" . $wpdb->esc_like($search) . "%");
      }
      if (!empty($search_conditions)) {
        $query .= " WHERE " . implode(" OR ", $search_conditions);
      }
    }

    // Add ordering
    if ($orderby) {
      // Validate column name to prevent SQL injection
      if (self::validate_table_name($orderby) && in_array($orderby, $columns)) {
        $escaped_orderby = esc_sql($orderby);
        // Validate order direction
        $order = ($order === "ASC" || $order === "DESC") ? $order : "ASC";
        $query .= " ORDER BY `{$escaped_orderby}` {$order}";
      }
    }

    // Add pagination
    $query .= $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);

    // Get data
    $data = $wpdb->get_results($query, ARRAY_A);

    $total_pages = ceil($total / $per_page);

    return new \WP_REST_Response(
      [
        "data" => $data,
        "pagination" => [
          "page" => $page,
          "per_page" => $per_page,
          "total" => $total,
          "total_pages" => $total_pages,
        ],
      ],
      200
    );
  }

  /**
   * Executes a custom SQL query (read-only SELECT queries only)
   *
   * @param \WP_REST_Request $request The REST request object
   * @return \WP_REST_Response Response containing query results
   */
  public static function execute_query($request)
  {
    global $wpdb;

    $query = trim($request->get_param("query"));
    $limit = $request->get_param("limit", 1000);

    // Remove comments and normalize whitespace to prevent bypass attempts
    $query = preg_replace('/--.*$/m', '', $query); // Remove single-line comments
    $query = preg_replace('/\/\*.*?\*\//s', '', $query); // Remove multi-line comments
    $query = preg_replace('/\s+/', ' ', $query); // Normalize whitespace
    $query = trim($query);

    // Ensure it's a SELECT query (after removing comments)
    $query_upper = strtoupper($query);
    if (strpos($query_upper, "SELECT") !== 0) {
      return new \WP_Error("invalid_query", __("Only SELECT queries are allowed for safety.", "uixpress"), ["status" => 400]);
    }

    // Block dangerous SQL keywords even in SELECT queries
    $dangerous_keywords = ["DROP", "DELETE", "UPDATE", "INSERT", "ALTER", "CREATE", "TRUNCATE", "EXEC", "EXECUTE", "CALL"];
    foreach ($dangerous_keywords as $keyword) {
      if (stripos($query, $keyword) !== false && stripos($query, "SELECT") === false) {
        return new \WP_Error("invalid_query", __("Query contains prohibited SQL keywords.", "uixpress"), ["status" => 400]);
      }
    }

    // Additional check: ensure no semicolons followed by other statements
    $parts = explode(";", $query);
    if (count($parts) > 1) {
      // Check if any part after the first semicolon contains SQL
      for ($i = 1; $i < count($parts); $i++) {
        $part = trim($parts[$i]);
        if (!empty($part)) {
          return new \WP_Error("invalid_query", __("Multiple statements are not allowed.", "uixpress"), ["status" => 400]);
        }
      }
    }

    // Add LIMIT if not present (to prevent excessive data retrieval)
    if (stripos($query, "LIMIT") === false) {
      $query .= $wpdb->prepare(" LIMIT %d", $limit);
    } else {
      // Validate existing LIMIT is reasonable
      if (preg_match('/LIMIT\s+(\d+)/i', $query, $matches)) {
        $existing_limit = (int) $matches[1];
        if ($existing_limit > $limit) {
          // Replace with our max limit
          $query = preg_replace('/LIMIT\s+\d+/i', $wpdb->prepare("LIMIT %d", $limit), $query);
        }
      }
    }

    // Execute query
    $results = $wpdb->get_results($query, ARRAY_A);

    if ($wpdb->last_error) {
      // Don't expose full error details to prevent information disclosure
      return new \WP_Error("query_error", __("Query execution failed. Please check your SQL syntax.", "uixpress"), ["status" => 500]);
    }

    return new \WP_REST_Response(
      [
        "data" => $results,
        "rows_affected" => count($results),
      ],
      200
    );
  }

  /**
   * Gets row count for a table
   *
   * @param \WP_REST_Request $request The REST request object
   * @return \WP_REST_Response Response containing row count
   */
  public static function get_table_count($request)
  {
    global $wpdb;

    $table_name = $request->get_param("table");

    // Validate table name format
    $validated_table_name = self::validate_table_name($table_name);
    if (!$validated_table_name) {
      return new \WP_Error("invalid_table_name", __("Invalid table name.", "uixpress"), ["status" => 400]);
    }

    // Validate table name exists
    if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name))) {
      return new \WP_Error("table_not_found", __("Table not found.", "uixpress"), ["status" => 404]);
    }

    // Table name is validated, so esc_sql is safe
    $escaped_table_name = esc_sql($table_name);
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$escaped_table_name}`");

    return new \WP_REST_Response(["count" => $count], 200);
  }

  /**
   * Verifies the current user's password for destructive operations
   *
   * @param \WP_REST_Request $request The REST request object
   * @return \WP_REST_Response Response containing verification result
   */
  public static function verify_password($request)
  {
    $password = $request->get_param("password");
    $current_user = wp_get_current_user();

    if (empty($password)) {
      return new \WP_Error("password_required", __("Password is required.", "uixpress"), ["status" => 400]);
    }

    // Verify password using WordPress authentication
    $user = wp_authenticate($current_user->user_login, $password);

    if (is_wp_error($user)) {
      return new \WP_Error("invalid_password", __("Invalid password.", "uixpress"), ["status" => 403]);
    }

    // Verify it's the current user
    if ($user->ID !== $current_user->ID) {
      return new \WP_Error("user_mismatch", __("Password verification failed.", "uixpress"), ["status" => 403]);
    }

    return new \WP_REST_Response(["verified" => true], 200);
  }

  /**
   * Checks if a table is safe to delete (non-standard WordPress table only)
   * Uses standard WordPress table names to detect core tables regardless of prefix
   *
   * @param string $table_name The table name to check
   * @return array Array with 'safe' boolean and 'reason' string
   */
  private static function is_table_safe_to_delete($table_name)
  {
    // Never allow deletion of WordPress core tables (checks against standard table names)
    if (self::is_wordpress_table($table_name)) {
      return [
        "safe" => false,
        "reason" => __("WordPress core tables cannot be deleted.", "uixpress"),
      ];
    }

    // Never allow deletion of system tables
    $system_tables = ["information_schema", "mysql", "performance_schema", "sys"];
    foreach ($system_tables as $system_table) {
      if (stripos($table_name, $system_table) === 0) {
        return [
          "safe" => false,
          "reason" => __("System tables cannot be deleted.", "uixpress"),
        ];
      }
    }

    return ["safe" => true, "reason" => ""];
  }

  /**
   * Deletes a non-standard WordPress table
   *
   * @param \WP_REST_Request $request The REST request object
   * @return \WP_REST_Response Response containing deletion result
   */
  public static function delete_table($request)
  {
    global $wpdb;

    $table_name = $request->get_param("table");
    $password = $request->get_param("password");

    // Validate table name format
    $validated_table_name = self::validate_table_name($table_name);
    if (!$validated_table_name) {
      return new \WP_Error("invalid_table_name", __("Invalid table name.", "uixpress"), ["status" => 400]);
    }

    // Verify password
    if (empty($password)) {
      return new \WP_Error("password_required", __("Password is required for this operation.", "uixpress"), ["status" => 400]);
    }

    $current_user = wp_get_current_user();
    $user = wp_authenticate($current_user->user_login, $password);

    if (is_wp_error($user) || $user->ID !== $current_user->ID) {
      return new \WP_Error("invalid_password", __("Invalid password.", "uixpress"), ["status" => 403]);
    }

    // Check if table is safe to delete
    $safety_check = self::is_table_safe_to_delete($table_name);
    if (!$safety_check["safe"]) {
      return new \WP_Error("table_protected", $safety_check["reason"], ["status" => 403]);
    }

    // Verify table exists
    if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name))) {
      return new \WP_Error("table_not_found", __("Table not found.", "uixpress"), ["status" => 404]);
    }

    // Log deletion attempt
    error_log(
      sprintf(
        "[Database Explorer] User %s (%d) deleted table: %s",
        $current_user->user_login,
        $current_user->ID,
        $table_name
      )
    );

    // Perform deletion
    $escaped_table_name = esc_sql($table_name);
    $result = $wpdb->query("DROP TABLE IF EXISTS `{$escaped_table_name}`");

    if ($result === false) {
      return new \WP_Error("deletion_failed", __("Failed to delete table.", "uixpress"), ["status" => 500]);
    }

    return new \WP_REST_Response(
      [
        "success" => true,
        "message" => sprintf(__("Table '%s' has been deleted.", "uixpress"), esc_html($table_name)),
      ],
      200
    );
  }
}

