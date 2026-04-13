<?php

namespace UiXpress\Rest;

use UiXpress\Activity\ActivityLogger;
use UiXpress\Activity\ActivityCron;
use UiXpress\Activity\ActivityDatabase;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class ActivityLog
 *
 * REST API endpoints for activity log management
 * 
 * @since 1.0.0
 */
class ActivityLog
{
    /**
     * ActivityLog constructor.
     */
    public function __construct()
    {
        add_action("rest_api_init", [$this, "register_custom_endpoints"]);
    }

    /**
     * Registers custom REST API endpoints
     * 
     * @return void
     * @since 1.0.0
     */
    public function register_custom_endpoints()
    {
        // Get activity logs
        register_rest_route('uixpress/v1', '/activity-log', [
            'methods' => 'GET',
            'callback' => [$this, 'get_logs'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'default' => 30,
                    'sanitize_callback' => 'absint',
                ],
                'user_id' => [
                    'default' => null,
                    'sanitize_callback' => 'absint',
                ],
                'action' => [
                    'default' => null,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'object_type' => [
                    'default' => null,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'object_id' => [
                    'default' => null,
                    'sanitize_callback' => 'absint',
                ],
                'search' => [
                    'default' => null,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'date_from' => [
                    'default' => null,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'date_to' => [
                    'default' => null,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'orderby' => [
                    'default' => 'created_at',
                    'validate_callback' => function ($param) {
                        $allowed = ['id', 'user_id', 'action', 'object_type', 'object_id', 'created_at'];
                        return in_array($param, $allowed, true);
                    },
                    'sanitize_callback' => function ($param) {
                        $allowed = ['id', 'user_id', 'action', 'object_type', 'object_id', 'created_at'];
                        return in_array($param, $allowed, true) ? $param : 'created_at';
                    },
                ],
                'order' => [
                    'default' => 'DESC',
                    'validate_callback' => function ($param) {
                        return in_array(strtoupper($param), ['ASC', 'DESC'], true);
                    },
                    'sanitize_callback' => function ($param) {
                        return strtoupper($param) === 'ASC' ? 'ASC' : 'DESC';
                    },
                ],
            ],
        ]);

        // Get single log entry
        register_rest_route('uixpress/v1', '/activity-log/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_log'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    },
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Get statistics
        register_rest_route('uixpress/v1', '/activity-log/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_permissions'],
            'args' => [
                'date_from' => [
                    'default' => null,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'date_to' => [
                    'default' => null,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Manual cleanup
        register_rest_route('uixpress/v1', '/activity-log/cleanup', [
            'methods' => 'POST',
            'callback' => [$this, 'manual_cleanup'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }

    /**
     * Converts MySQL datetime to ISO 8601 with site timezone for correct frontend display.
     * WordPress stores activity timestamps in site timezone (current_time('mysql')).
     * Without timezone info, the frontend would incorrectly treat them as UTC.
     *
     * @param string|null $created_at MySQL datetime (Y-m-d H:i:s) in site timezone
     * @return string ISO 8601 datetime with timezone offset, or original value if invalid
     * @since 1.0.0
     */
    private function format_created_at_for_response($created_at)
    {
        if (empty($created_at)) {
            return $created_at;
        }

        try {
            $timezone = wp_timezone();
            $date = new \DateTime($created_at, $timezone);
            return $date->format('c'); // ISO 8601: 2024-01-15T14:30:00+06:00
        } catch (\Exception $e) {
            return $created_at;
        }
    }

    /**
     * Checks if the user has permission to access the endpoint
     *
     * @param \WP_REST_Request $request The request object
     * @return bool|\WP_Error True if the user has permission, WP_Error object otherwise
     * @since 1.0.0
     */
    public function check_permissions($request)
    {
        // Check if activity logger is enabled
        if (!ActivityDatabase::is_activity_logger_enabled()) {
            return new \WP_Error('rest_forbidden', __('Activity logger is not enabled.', 'uixpress'), ['status' => 403]);
        }
        
        // Check permissions using utility class
        return RestPermissionChecker::check_permissions($request, 'manage_options');
    }

    /**
     * Gets activity logs
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response The response object
     * @since 1.0.0
     */
    public function get_logs($request)
    {
        $args = [
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
            'user_id' => $request->get_param('user_id'),
            'action' => $request->get_param('action'),
            'object_type' => $request->get_param('object_type'),
            'object_id' => $request->get_param('object_id'),
            'search' => $request->get_param('search'),
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
            'orderby' => $request->get_param('orderby'),
            'order' => $request->get_param('order'),
        ];

        $result = ActivityLogger::get_logs($args);

        // Enhance logs with user data and convert timestamps to ISO 8601 with site timezone
        $can_view_emails = current_user_can('list_users');
        foreach ($result['logs'] as &$log) {
            $user = get_userdata($log['user_id']);
            $log['user'] = [
                'id' => $log['user_id'],
                'name' => $user ? $user->display_name : __('Unknown', 'uixpress'),
                'email' => ($can_view_emails && $user) ? $user->user_email : '',
                'avatar' => $user ? get_avatar_url($user->ID) : '',
            ];
            $log['created_at'] = $this->format_created_at_for_response($log['created_at']);
        }

        $response = new \WP_REST_Response($result['logs']);
        $response->header('X-WP-Total', $result['total']);
        $response->header('X-WP-TotalPages', $result['total_pages']);

        return $response;
    }

    /**
     * Gets a single log entry
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response|\WP_Error The response object or error
     * @since 1.0.0
     */
    public function get_log($request)
    {
        $log_id = $request->get_param('id');
        $log = ActivityLogger::get_log($log_id);

        if (!$log) {
            return new \WP_Error('not_found', __('Log entry not found.', 'uixpress'), ['status' => 404]);
        }

        // Enhance log with user data and convert timestamp to ISO 8601 with site timezone
        $can_view_emails = current_user_can('list_users');
        $user = get_userdata($log['user_id']);
        $log['user'] = [
            'id' => $log['user_id'],
            'name' => $user ? $user->display_name : __('Unknown', 'uixpress'),
            'email' => ($can_view_emails && $user) ? $user->user_email : '',
            'avatar' => $user ? get_avatar_url($user->ID) : '',
        ];
        $log['created_at'] = $this->format_created_at_for_response($log['created_at']);

        return new \WP_REST_Response($log);
    }

    /**
     * Gets activity statistics
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response The response object
     * @since 1.0.0
     */
    public function get_stats($request)
    {
        $args = [
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
        ];

        $stats = ActivityLogger::get_stats($args);

        // Enhance top users with user data
        $can_view_emails = current_user_can('list_users');
        foreach ($stats['top_users'] as &$user_stat) {
            $user = get_userdata($user_stat['user_id']);
            $user_stat['user'] = [
                'id' => $user_stat['user_id'],
                'name' => $user ? $user->display_name : __('Unknown', 'uixpress'),
                'email' => ($can_view_emails && $user) ? $user->user_email : '',
            ];
        }

        return new \WP_REST_Response($stats);
    }

    /**
     * Manually triggers cleanup of old logs
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response The response object
     * @since 1.0.0
     */
    public function manual_cleanup($request)
    {
        $deleted = ActivityCron::manual_cleanup();

        return new \WP_REST_Response([
            'success' => true,
            'deleted' => $deleted,
            'message' => sprintf(__('Cleaned up %d old log entries.', 'uixpress'), $deleted),
        ]);
    }
}

