<?php

namespace UiXpress\Activity;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class ActivityLogger
 * 
 * Handles logging of user activities and actions
 * 
 * @since 1.0.0
 */
class ActivityLogger
{
    /**
     * Queue for batched inserts
     * 
     * @var array
     */
    private static $log_queue = [];

    /**
     * ActivityLogger constructor.
     */
    public function __construct()
    {
        // Register custom cron interval
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        
        // Flush queue on shutdown for batch inserts
        add_action('shutdown', [self::class, 'flush_log_queue'], 999);
        
        // Flush queue periodically (every 30 seconds)
        if (!wp_next_scheduled('uixpress_activity_log_flush')) {
            wp_schedule_event(time(), 'uixpress_30_seconds', 'uixpress_activity_log_flush');
        }
        add_action('uixpress_activity_log_flush', [self::class, 'flush_log_queue']);
    }

    /**
     * Adds custom cron interval (30 seconds)
     * 
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     * @since 1.0.0
     */
    public function add_cron_interval($schedules)
    {
        $schedules['uixpress_30_seconds'] = [
            'interval' => 30,
            'display' => __('Every 30 Seconds', 'uixpress'),
        ];
        return $schedules;
    }

    /**
     * Logs an activity to the database
     * 
     * @param string $action Action type (e.g., 'created', 'updated', 'deleted')
     * @param string $object_type Object type (e.g., 'post', 'user', 'comment')
     * @param int|null $object_id Object ID
     * @param array|null $old_value Old value snapshot (only changed fields)
     * @param array|null $new_value New value snapshot (only changed fields)
     * @param array|null $metadata Additional metadata
     * @param int|null $user_id User ID (defaults to current user)
     * @return bool|int Log ID on success, false on failure
     * @since 1.0.0
     */
    public static function log($action, $object_type, $object_id = null, $old_value = null, $new_value = null, $metadata = null, $user_id = null)
    {
        // Check if activity logger is enabled
        if (!ActivityDatabase::is_activity_logger_enabled()) {
            return false;
        }

        // Get current user if not provided
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        // Don't log if no user
        if (!$user_id) {
            return false;
        }

        // Check log level setting
        $settings = get_option('uixpress_settings', []);
        $log_level = isset($settings['activity_log_level']) ? $settings['activity_log_level'] : 'important';
        
        // If log level is 'important', only log important actions
        if ($log_level === 'important' && !self::is_important_action($action, $object_type)) {
            return false;
        }

        // Get IP address
        $ip_address = self::get_client_ip();

        // Get user agent
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : null;

        // Prepare data
        $log_data = [
            'user_id' => absint($user_id),
            'action' => sanitize_text_field($action),
            'object_type' => sanitize_text_field($object_type),
            'object_id' => $object_id ? absint($object_id) : null,
            'old_value' => $old_value ? wp_json_encode(self::sanitize_log_data($old_value)) : null,
            'new_value' => $new_value ? wp_json_encode(self::sanitize_log_data($new_value)) : null,
            'ip_address' => $ip_address ? sanitize_text_field($ip_address) : null,
            'user_agent' => $user_agent,
            'metadata' => $metadata ? wp_json_encode(self::sanitize_log_data($metadata)) : null,
            'created_at' => current_time('mysql'),
        ];

        // Add to queue for batch insert
        self::$log_queue[] = $log_data;

        // Return true to indicate queued
        return true;
    }

    /**
     * Flushes the log queue to database
     * 
     * @return void
     * @since 1.0.0
     */
    public static function flush_log_queue()
    {
        if (empty(self::$log_queue)) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'uixpress_activity_log';

        // Check if table exists
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name))) {
            return;
        }

        // Batch insert using insert method for better compatibility
        foreach (self::$log_queue as $log_data) {
            $wpdb->insert(
                $table_name,
                [
                    'user_id' => $log_data['user_id'],
                    'action' => $log_data['action'],
                    'object_type' => $log_data['object_type'],
                    'object_id' => $log_data['object_id'],
                    'old_value' => $log_data['old_value'],
                    'new_value' => $log_data['new_value'],
                    'ip_address' => $log_data['ip_address'],
                    'user_agent' => $log_data['user_agent'],
                    'metadata' => $log_data['metadata'],
                    'created_at' => $log_data['created_at'],
                ],
                [
                    '%d',
                    '%s',
                    '%s',
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                ]
            );
        }

        // Clear queue
        self::$log_queue = [];
    }

    /**
     * Checks if an action is considered important
     * 
     * @param string $action Action type
     * @param string $object_type Object type
     * @return bool True if important, false otherwise
     * @since 1.0.0
     */
    private static function is_important_action($action, $object_type)
    {
        $important_actions = [
            'deleted',
            'trashed',
            'restored',
            'activated',
            'deactivated',
            'installed',
            'uninstalled',
            'role_changed',
            'permission_changed',
        ];

        return in_array($action, $important_actions, true);
    }

    /**
     * Sanitizes log data to remove sensitive information
     * 
     * @param array|mixed $data Data to sanitize
     * @return array|mixed Sanitized data
     * @since 1.0.0
     */
    private static function sanitize_log_data($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitive_keys = [
            'password',
            'user_pass',
            'pass',
            'api_key',
            'secret',
            'token',
            'private_key',
            'auth_key',
        ];

        $sanitized = [];
        foreach ($data as $key => $value) {
            $lower_key = strtolower($key);
            
            // Skip sensitive fields
            foreach ($sensitive_keys as $sensitive) {
                if (strpos($lower_key, $sensitive) !== false) {
                    $sanitized[$key] = '[REDACTED]';
                    continue 2;
                }
            }

            // Recursively sanitize arrays
            if (is_array($value)) {
                $sanitized[$key] = self::sanitize_log_data($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Gets the client IP address
     * 
     * @return string|null IP address or null
     * @since 1.0.0
     */
    private static function get_client_ip()
    {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field($_SERVER[$key]);
                // Handle comma-separated IPs (from proxies)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Retrieves activity logs with filtering and pagination
     * 
     * @param array $args Query arguments
     * @return array Logs and pagination info
     * @since 1.0.0
     */
    public static function get_logs($args = [])
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'uixpress_activity_log';

        $defaults = [
            'page' => 1,
            'per_page' => 30,
            'user_id' => null,
            'action' => null,
            'object_type' => null,
            'object_id' => null,
            'search' => null,
            'date_from' => null,
            'date_to' => null,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        // Build WHERE clause
        $where = ['1=1'];
        $where_values = [];

        if ($args['user_id']) {
            $where[] = 'user_id = %d';
            $where_values[] = absint($args['user_id']);
        }

        if ($args['action']) {
            $where[] = 'action = %s';
            $where_values[] = sanitize_text_field($args['action']);
        }

        if ($args['object_type']) {
            $where[] = 'object_type = %s';
            $where_values[] = sanitize_text_field($args['object_type']);
        }

        if ($args['object_id']) {
            $where[] = 'object_id = %d';
            $where_values[] = absint($args['object_id']);
        }

        if ($args['date_from']) {
            $where[] = 'created_at >= %s';
            $where_values[] = sanitize_text_field($args['date_from']);
        }

        if ($args['date_to']) {
            $where[] = 'created_at <= %s';
            $where_values[] = sanitize_text_field($args['date_to']);
        }

        if ($args['search']) {
            $search = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $where[] = '(action LIKE %s OR object_type LIKE %s OR old_value LIKE %s OR new_value LIKE %s OR metadata LIKE %s)';
            $where_values[] = $search;
            $where_values[] = $search;
            $where_values[] = $search;
            $where_values[] = $search;
            $where_values[] = $search;
        }

        $where_clause = implode(' AND ', $where);

        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        $total = $wpdb->get_var($count_query);

        // Get logs
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        // Whitelist allowed orderby columns for security
        $allowed_orderby = ['id', 'user_id', 'action', 'object_type', 'object_id', 'created_at'];
        $orderby_column = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';
        
        // Validate order direction
        $order_direction = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        $orderby = sanitize_sql_orderby($orderby_column . ' ' . $order_direction);
        if (!$orderby) {
            $orderby = 'created_at DESC';
        }

        $query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, [$args['per_page'], $offset]);
        $query = $wpdb->prepare($query, $query_values);

        $logs = $wpdb->get_results($query, ARRAY_A);

        // Decode JSON fields
        foreach ($logs as &$log) {
            if ($log['old_value']) {
                $log['old_value'] = json_decode($log['old_value'], true);
            }
            if ($log['new_value']) {
                $log['new_value'] = json_decode($log['new_value'], true);
            }
            if ($log['metadata']) {
                $log['metadata'] = json_decode($log['metadata'], true);
            }
        }

        return [
            'logs' => $logs,
            'total' => (int) $total,
            'page' => (int) $args['page'],
            'per_page' => (int) $args['per_page'],
            'total_pages' => (int) ceil($total / $args['per_page']),
        ];
    }

    /**
     * Gets a single log entry by ID
     * 
     * @param int $log_id Log ID
     * @return array|null Log entry or null
     * @since 1.0.0
     */
    public static function get_log($log_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'uixpress_activity_log';

        $log = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", absint($log_id)),
            ARRAY_A
        );

        if (!$log) {
            return null;
        }

        // Decode JSON fields
        if ($log['old_value']) {
            $log['old_value'] = json_decode($log['old_value'], true);
        }
        if ($log['new_value']) {
            $log['new_value'] = json_decode($log['new_value'], true);
        }
        if ($log['metadata']) {
            $log['metadata'] = json_decode($log['metadata'], true);
        }

        return $log;
    }

    /**
     * Gets activity statistics
     * 
     * @param array $args Query arguments
     * @return array Statistics
     * @since 1.0.0
     */
    public static function get_stats($args = [])
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'uixpress_activity_log';

        $defaults = [
            'date_from' => null,
            'date_to' => null,
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $where_values = [];

        if ($args['date_from']) {
            $where[] = 'created_at >= %s';
            $where_values[] = sanitize_text_field($args['date_from']);
        }

        if ($args['date_to']) {
            $where[] = 'created_at <= %s';
            $where_values[] = sanitize_text_field($args['date_to']);
        }

        $where_clause = implode(' AND ', $where);

        // Total logs
        $total_query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
        if (!empty($where_values)) {
            $total_query = $wpdb->prepare($total_query, $where_values);
        }
        $total = (int) $wpdb->get_var($total_query);

        // Actions breakdown
        $actions_query = "SELECT action, COUNT(*) as count FROM {$table_name} WHERE {$where_clause} GROUP BY action ORDER BY count DESC";
        if (!empty($where_values)) {
            $actions_query = $wpdb->prepare($actions_query, $where_values);
        }
        $actions = $wpdb->get_results($actions_query, ARRAY_A);

        // Object types breakdown
        $types_query = "SELECT object_type, COUNT(*) as count FROM {$table_name} WHERE {$where_clause} GROUP BY object_type ORDER BY count DESC";
        if (!empty($where_values)) {
            $types_query = $wpdb->prepare($types_query, $where_values);
        }
        $types = $wpdb->get_results($types_query, ARRAY_A);

        // Top users
        $users_query = "SELECT user_id, COUNT(*) as count FROM {$table_name} WHERE {$where_clause} GROUP BY user_id ORDER BY count DESC LIMIT 10";
        if (!empty($where_values)) {
            $users_query = $wpdb->prepare($users_query, $where_values);
        }
        $top_users = $wpdb->get_results($users_query, ARRAY_A);

        return [
            'total' => $total,
            'actions' => $actions,
            'object_types' => $types,
            'top_users' => $top_users,
        ];
    }
}

