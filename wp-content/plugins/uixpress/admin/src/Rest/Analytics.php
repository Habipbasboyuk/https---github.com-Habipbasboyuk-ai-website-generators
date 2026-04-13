<?php

namespace UiXpress\Rest;

use UiXpress\Analytics\AnalyticsDatabase;
use UiXpress\Analytics\AnalyticsProviderRouter;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class Analytics
 * 
 * REST API endpoints for UiXpress Analytics functionality
 * Handles fetching analytics stats and inserting new analytics data
 */
class Analytics
{
    /**
     * Analytics constructor.
     */
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_analytics_routes']);
    }

    /**
     * Registers analytics REST API routes
     * 
     * @return void
     * @since 1.0.0
     */
    public function register_analytics_routes()
    {
        // Only register routes if analytics is enabled
        if (!AnalyticsDatabase::is_analytics_enabled()) {
            return;
        }

        // Route for fetching active users
        register_rest_route('uixpress/v1', '/analytics/active-users', [
            'methods' => 'GET',
            'callback' => [$this, 'get_active_users'],
            'permission_callback' => [$this, 'check_analytics_view_permissions'],
            'args' => [
                'timezone' => [
                    'required' => false,
                    'default' => null,
                    'type' => 'string',
                    'description' => 'Browser timezone for accurate time calculations'
                ],
                'browser_time' => [
                    'required' => false,
                    'default' => null,
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'Browser time in ISO format'
                ]
            ]
        ]);

        // Route for fetching analytics stats
        register_rest_route('uixpress/v1', '/analytics/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_analytics_stats'],
            'permission_callback' => [$this, 'check_analytics_view_permissions'],
            'args' => [
                'start_date' => [
                    'required' => false,
                    'default' => null,
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'Start date for analytics data (ISO 8601 format)'
                ],
                'end_date' => [
                    'required' => false,
                    'default' => null,
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'End date for analytics data (ISO 8601 format)'
                ],
                'page_url' => [
                    'required' => false,
                    'default' => null,
                    'type' => 'string',
                    'description' => 'Filter by specific page URL'
                ],
                'stat_type' => [
                    'required' => false,
                    'default' => 'overview',
                    'type' => 'string',
                    'enum' => ['overview', 'pages', 'referrers', 'devices', 'geo', 'events'],
                    'description' => 'Type of analytics stats to retrieve'
                ]
            ]
        ]);

        // Route for inserting new analytics data
        register_rest_route('uixpress/v1', '/analytics/track', [
            'methods' => 'POST',
            'callback' => [$this, 'track_analytics_event'],
            'permission_callback' => [$this, 'check_analytics_track_permissions'],
            'args' => [
                'page_url' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => function($value) {
                        return sanitize_url($value);
                    },
                    'validate_callback' => function($value) {
                        return filter_var($value, FILTER_VALIDATE_URL) !== false;
                    },
                    'description' => 'URL of the page being viewed'
                ],
                'page_title' => [
                    'required' => false,
                    'default' => null,
                    'type' => 'string',
                    'allow_empty' => true,
                    'sanitize_callback' => function($value) {
                        return empty($value) ? null : sanitize_text_field($value);
                    },
                    'description' => 'Title of the page being viewed'
                ],
                'referrer' => [
                    'required' => false,
                    'default' => null,
                    'type' => 'string',
                    'allow_empty' => true,
                    'sanitize_callback' => function($value) {
                        return empty($value) ? null : sanitize_url($value);
                    },
                    'description' => 'Referrer URL'
                ],
                'user_agent' => [
                    'required' => false,
                    'default' => null,
                    'type' => 'string',
                    'allow_empty' => true,
                    'sanitize_callback' => function($value) {
                        return empty($value) ? null : sanitize_text_field($value);
                    },
                    'description' => 'User agent string'
                ],
                'session_id' => [
                    'required' => false,
                    'default' => null,
                    'type' => 'string',
                    'description' => 'Client-side session ID'
                ]
            ]
        ]);

        // Route for getting analytics settings
        register_rest_route('uixpress/v1', '/analytics/settings', [
            'methods' => 'GET',
            'callback' => [$this, 'get_analytics_settings'],
            'permission_callback' => [$this, 'check_analytics_view_permissions']
        ]);

        // Route for updating analytics settings
        register_rest_route('uixpress/v1', '/analytics/settings', [
            'methods' => 'POST',
            'callback' => [$this, 'update_analytics_settings'],
            'permission_callback' => [$this, 'check_analytics_permissions'],
            'args' => [
                'settings' => [
                    'required' => true,
                    'type' => 'object',
                    'description' => 'Analytics settings to update'
                ]
            ]
        ]);

        // Route for fetching chart data
        register_rest_route('uixpress/v1', '/analytics/chart', [
            'methods' => 'GET',
            'callback' => [$this, 'get_chart_data'],
            'permission_callback' => [$this, 'check_analytics_view_permissions'],
            'args' => [
                'start_date' => [
                    'required' => false,
                    'default' => null,
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'Start date for chart data (ISO 8601 format)'
                ],
                'end_date' => [
                    'required' => false,
                    'default' => null,
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'End date for chart data (ISO 8601 format)'
                ],
                'chart_type' => [
                    'required' => false,
                    'default' => 'pageviews',
                    'type' => 'string',
                    'enum' => ['pageviews', 'visitors', 'both'],
                    'description' => 'Type of chart data to retrieve'
                ]
            ]
        ]);
    }

    /**
     * Checks if user has permission to view analytics data
     * 
     * @param \WP_REST_Request $request The REST request object
     * @return bool|WP_Error True if user has permission, WP_Error otherwise
     * @since 1.0.0
     */
    public function check_analytics_view_permissions($request)
    {
        return RestPermissionChecker::check_permissions($request, 'edit_posts');
    }

    /**
     * Checks if user has permission to track analytics events
     * 
     * Allows unauthenticated users to track analytics events for public analytics.
     * If a nonce is provided, it will be verified. For unauthenticated users without
     * a nonce, the request is still allowed but will be subject to rate limiting.
     * 
     * @param \WP_REST_Request $request The REST request object
     * @return bool True if user has permission, false otherwise
     * @since 1.0.0
     */
    public function check_analytics_track_permissions($request)
    {
        // If user is logged in, verify nonce for security
        if (is_user_logged_in()) {
            $nonce = $request->get_header('X-WP-Nonce');
            if (!$nonce) {
                // Try to get nonce from request body if not in header
                $nonce = $request->get_param('_wpnonce');
            }

            // For logged-in users, require valid nonce
            if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
                return false;
            }
        }

        // Allow unauthenticated users to track analytics
        // Security is handled through rate limiting and input validation
        return true;
    }

    /**
     * Checks if user has permission to manage analytics settings
     * 
     * @param \WP_REST_Request $request The REST request object
     * @return bool|WP_Error True if user has permission, WP_Error otherwise
     * @since 1.0.0
     */
    public function check_analytics_permissions($request)
    {
        return RestPermissionChecker::check_permissions($request, 'manage_options');
    }

    /**
     * Gets active users count (users active in last 5 minutes)
     * 
     * This endpoint explicitly disables caching to ensure real-time active user data.
     * 
     * @param \WP_REST_Request $request The REST request object
     * @return \WP_REST_Response|\WP_Error Response with active users data or error
     * @since 1.0.0
     */
    public function get_active_users($request)
    {
        try {
            $timezone = $request->get_param('timezone');
            $browser_time = $request->get_param('browser_time');
            
            // Get the active analytics provider
            $provider = AnalyticsProviderRouter::getProvider();
            $data = $provider->getActiveUsers($timezone, $browser_time);
            
            // Add provider info
            $data['_provider'] = $provider->getIdentifier();
            
            // Check for provider errors (Google Analytics specific)
            if (method_exists($provider, 'getLastError')) {
                $last_error = $provider->getLastError();
                if ($last_error) {
                    $data['_error'] = $last_error;
                }
            }
            
            $response = new \WP_REST_Response($data, 200);
            
            // Explicitly disable caching for active users endpoint
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            
            return $response;
        } catch (\Exception $e) {
            return new \WP_Error('analytics_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Gets analytics statistics based on the requested parameters
     * 
     * Uses the analytics provider router to fetch data from the configured provider
     * (UIXpress built-in or Google Analytics).
     * 
     * @param \WP_REST_Request $request The REST request object
     * @return \WP_REST_Response|\WP_Error Response with analytics data or error
     * @since 1.0.0
     */
    public function get_analytics_stats($request)
    {
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        $page_url = $request->get_param('page_url');
        $stat_type = $request->get_param('stat_type');

        try {
            // Get the active analytics provider
            $provider = AnalyticsProviderRouter::getProvider();

            switch ($stat_type) {
                case 'overview':
                    $data = $provider->getOverview($start_date, $end_date, $page_url);
                    break;
                case 'pages':
                    $data = $provider->getPages($start_date, $end_date, $page_url);
                    break;
                case 'referrers':
                    $data = $provider->getReferrers($start_date, $end_date);
                    break;
                case 'devices':
                    $data = $provider->getDevices($start_date, $end_date);
                    break;
                case 'geo':
                    $data = $provider->getGeo($start_date, $end_date);
                    break;
                case 'events':
                    $data = $provider->getEvents($start_date, $end_date);
                    break;
                default:
                    return new \WP_Error('invalid_stat_type', 'Invalid stat type', ['status' => 400]);
            }

         

            // Add provider info to response
            $data['_provider'] = $provider->getIdentifier();
            
            // Check for provider errors (Google Analytics specific)
            if (method_exists($provider, 'getLastError')) {
                $last_error = $provider->getLastError();
                if ($last_error) {
                    $data['_error'] = $last_error;
                }
            }

            return new \WP_REST_Response($data, 200);
        } catch (\Exception $e) {
            return new \WP_Error('analytics_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Tracks a new analytics event (page view)
     * 
     * @param \WP_REST_Request $request The REST request object
     * @return \WP_REST_Response|\WP_Error Response with tracking result or error
     * @since 1.0.0
     */
    public function track_analytics_event($request)
    {
        try {
            // Get parameters (already sanitized by REST API callbacks)
            $page_url = $request->get_param('page_url');
            $page_title = $request->get_param('page_title');
            $referrer = $request->get_param('referrer');
            $user_agent = $request->get_param('user_agent');
            $client_session_id = sanitize_text_field($request->get_param('session_id'));

            if (empty($page_url)) {
                return new \WP_Error('missing_page_url', 'Page URL is required', ['status' => 400]);
            }
            
            // Add input validation and length limits
            if (strlen($page_url) > 500) {
                return new \WP_Error('url_too_long', 'URL exceeds maximum length', ['status' => 400]);
            }
            if (strlen($page_title) > 255) {
                return new \WP_Error('title_too_long', 'Page title exceeds maximum length', ['status' => 400]);
            }
            if (strlen($referrer) > 500) {
                return new \WP_Error('referrer_too_long', 'Referrer URL exceeds maximum length', ['status' => 400]);
            }
            
            // Add rate limiting to prevent abuse
            $ip_hash = isset($_SERVER['REMOTE_ADDR']) ? hash('sha256', sanitize_text_field($_SERVER['REMOTE_ADDR'])) : 'unknown';
            $transient_key = 'uixpress_analytics_rate_limit_' . substr($ip_hash, 0, 16);
            $requests = get_transient($transient_key) ?: 0;
            if ($requests > 100) { // 100 requests per hour
                return new \WP_Error('rate_limit', 'Too many requests', ['status' => 429]);
            }
            set_transient($transient_key, $requests + 1, HOUR_IN_SECONDS);

            // Get client information - sanitize $_SERVER access
            $server_user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
            $server_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
            // Use WordPress helper for IP if available, otherwise use sanitized value
            $ip_address = function_exists('wp_get_ip_address') ? wp_get_ip_address() : $server_ip;
            
            $client_info = $this->parse_user_agent($user_agent ?: $server_user_agent);
            $geo_info = $this->get_geo_info();
            
            // Use client-provided session ID if available, otherwise generate one
            // This avoids PHP session issues in REST API context
            $session_id = $client_session_id ?: $this->get_or_create_session_id();
            
            // Validate session ID format (alphanumeric and dots, max 128 chars)
            // JavaScript generates IDs like: "abc123xyz.1234567890"
            if (empty($session_id) || !preg_match('/^[a-zA-Z0-9.]+$/', $session_id) || strlen($session_id) > 128) {
                // Generate new session ID if invalid
                $session_id = $this->get_or_create_session_id();
            }
            
            $ip_hash = $this->hash_ip($ip_address);

            // Check if this is a unique visitor for this session
            $is_unique = $this->is_unique_visitor($session_id, $page_url);

            // Insert page view record
            $pageview_id = $this->insert_page_view([
                'page_url' => $page_url,
                'page_title' => $page_title,
                'referrer' => $referrer,
                'referrer_domain' => $this->extract_domain($referrer),
                'user_agent' => $user_agent ?: $server_user_agent,
                'device_type' => $client_info['device_type'],
                'browser' => $client_info['browser'],
                'browser_version' => $client_info['browser_version'],
                'os' => $client_info['os'],
                'country_code' => $geo_info['country_code'],
                'city' => $geo_info['city'],
                'ip_hash' => $ip_hash,
                'session_id' => $session_id,
                'is_unique_visitor' => $is_unique ? 1 : 0,
                'created_at' => current_time('mysql')
            ]);

            if ($pageview_id) {
                return new \WP_REST_Response([
                    'success' => true,
                    'pageview_id' => $pageview_id,
                    'is_unique_visitor' => $is_unique
                ], 200);
            } else {
                return new \WP_Error('tracking_failed', 'Failed to track analytics event', ['status' => 500]);
            }
        } catch (\Exception $e) {
            return new \WP_Error('tracking_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Gets analytics settings
     * 
     * @param \WP_REST_Request $request The REST request object
     * @return \WP_REST_Response Response with analytics settings
     * @since 1.0.0
     */
    public function get_analytics_settings($request)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'uixpress_analytics_settings';
        // Escape table name to prevent SQL injection
        $table_name_escaped = $wpdb->_escape($table_name);
        $settings = $wpdb->get_results("SELECT setting_key, setting_value FROM `{$table_name_escaped}`", ARRAY_A);
        
        $formatted_settings = [];
        foreach ($settings as $setting) {
            $formatted_settings[$setting['setting_key']] = $setting['setting_value'];
        }

        return new \WP_REST_Response($formatted_settings, 200);
    }

    /**
     * Updates analytics settings
     * 
     * @param \WP_REST_Request $request The REST request object
     * @return \WP_REST_Response|\WP_Error Response with update result or error
     * @since 1.0.0
     */
    public function update_analytics_settings($request)
    {
        try {
            $settings = $request->get_param('settings');
            
            if (!is_array($settings)) {
                return new \WP_Error('invalid_settings', 'Settings must be an object', ['status' => 400]);
            }

            foreach ($settings as $key => $value) {
                AnalyticsDatabase::update_setting($key, $value);
            }

            return new \WP_REST_Response(['success' => true], 200);
        } catch (\Exception $e) {
            return new \WP_Error('settings_update_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Calculate comparison period dates
     * 
     * @param string $start_date Start date for current period
     * @param string $end_date End date for current period
     * @return array Array with comparison start and end dates
     * @since 1.0.0
     */
    private function get_comparison_period($start_date, $end_date)
    {
        $start = new \DateTime($start_date);
        $end = new \DateTime($end_date);
        
        // Calculate the duration of the current period
        $duration = $end->diff($start)->days;
        
        // Calculate comparison period (same duration, but before the current period)
        $comparison_end = clone $start;
        $comparison_end->sub(new \DateInterval('P1D')); // One day before current start
        
        $comparison_start = clone $comparison_end;
        $comparison_start->sub(new \DateInterval('P' . $duration . 'D')); // Subtract the same duration
        
        return [
            'start' => $comparison_start->format('Y-m-d H:i:s'),
            'end' => $comparison_end->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Gets overview statistics
     * 
     * @param string $start_date Start date for stats
     * @param string $end_date End date for stats
     * @param string|null $page_url Optional page URL filter
     * @return array Overview statistics
     * @since 1.0.0
     */
    private function get_overview_stats($start_date, $end_date, $page_url = null)
    {
        global $wpdb;
        
        $page_condition = $page_url ? $wpdb->prepare(" AND page_url = %s", $page_url) : "";
        
        $table_name = $wpdb->prefix . 'uixpress_analytics_daily';
        
        // Get current period stats
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                SUM(views) as total_views,
                SUM(unique_visitors) as total_unique_visitors,
                AVG(avg_time_on_page) as avg_time_on_page,
                AVG(bounce_rate) as avg_bounce_rate,
                COUNT(DISTINCT page_url) as unique_pages
            FROM {$table_name} 
            WHERE date >= %s AND date <= %s {$page_condition}
        ", $start_date, $end_date), ARRAY_A);

        // Get comparison period stats
        $comparison_period = $this->get_comparison_period($start_date, $end_date);
        $comparison_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                SUM(views) as total_views,
                SUM(unique_visitors) as total_unique_visitors,
                AVG(avg_time_on_page) as avg_time_on_page,
                AVG(bounce_rate) as avg_bounce_rate,
                COUNT(DISTINCT page_url) as unique_pages
            FROM {$table_name} 
            WHERE date >= %s AND date <= %s {$page_condition}
        ", $comparison_period['start'], $comparison_period['end']), ARRAY_A);

        return [
            'total_views' => (int) ($stats['total_views'] ?? 0),
            'total_unique_visitors' => (int) ($stats['total_unique_visitors'] ?? 0),
            'avg_time_on_page' => (float) ($stats['avg_time_on_page'] ?? 0),
            'avg_bounce_rate' => (float) ($stats['avg_bounce_rate'] ?? 0),
            'unique_pages' => (int) ($stats['unique_pages'] ?? 0),
            'comparison' => [
                'total_views' => (int) ($comparison_stats['total_views'] ?? 0),
                'total_unique_visitors' => (int) ($comparison_stats['total_unique_visitors'] ?? 0),
                'avg_time_on_page' => (float) ($comparison_stats['avg_time_on_page'] ?? 0),
                'avg_bounce_rate' => (float) ($comparison_stats['avg_bounce_rate'] ?? 0),
                'unique_pages' => (int) ($comparison_stats['unique_pages'] ?? 0),
                'period' => [
                    'start' => $comparison_period['start'],
                    'end' => $comparison_period['end']
                ]
            ]
        ];
    }

    /**
     * Gets pages statistics
     * 
     * @param string $start_date Start date for stats
     * @param string $end_date End date for stats
     * @param string|null $page_url Optional page URL filter
     * @return array Pages statistics
     * @since 1.0.0
     */
    private function get_pages_stats($start_date, $end_date, $page_url = null)
    {
        global $wpdb;
        
        $page_condition = $page_url ? $wpdb->prepare(" AND page_url = %s", $page_url) : "";
        
        $table_name = $wpdb->prefix . 'uixpress_analytics_daily';
        
        $pages = $wpdb->get_results($wpdb->prepare("
            SELECT 
                page_url,
                page_title,
                SUM(views) as total_views,
                SUM(unique_visitors) as total_unique_visitors,
                AVG(avg_time_on_page) as avg_time_on_page,
                AVG(bounce_rate) as bounce_rate
            FROM {$table_name} 
            WHERE date >= %s AND date <= %s {$page_condition}
            GROUP BY page_url, page_title
            ORDER BY total_views DESC
            LIMIT 50
        ", $start_date, $end_date), ARRAY_A);

        return $pages;
    }

    /**
     * Gets referrers statistics
     * 
     * @param string $start_date Start date for stats
     * @param string $end_date End date for stats
     * @return array Referrers statistics
     * @since 1.0.0
     */
    private function get_referrers_stats($start_date, $end_date)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'uixpress_analytics_referrers';
        
        $referrers = $wpdb->get_results($wpdb->prepare("
            SELECT 
                referrer_domain,
                SUM(visits) as total_visits,
                SUM(unique_visitors) as total_unique_visitors
            FROM {$table_name} 
            WHERE date >= %s AND date <= %s
            GROUP BY referrer_domain
            ORDER BY total_visits DESC
            LIMIT 20
        ", $start_date, $end_date), ARRAY_A);

        return $referrers;
    }

    /**
     * Gets devices statistics
     * 
     * @param string $start_date Start date for stats
     * @param string $end_date End date for stats
     * @return array Devices statistics
     * @since 1.0.0
     */
    private function get_devices_stats($start_date, $end_date)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'uixpress_analytics_devices';
        
        $devices = $wpdb->get_results($wpdb->prepare("
            SELECT 
                device_type,
                browser,
                os,
                SUM(views) as total_views,
                SUM(unique_visitors) as total_unique_visitors
            FROM {$table_name} 
            WHERE date >= %s AND date <= %s
            GROUP BY device_type, browser, os
            ORDER BY total_views DESC
            LIMIT 20
        ", $start_date, $end_date), ARRAY_A);

        return $devices;
    }

    /**
     * Gets geographic statistics
     * 
     * @param string $start_date Start date for stats
     * @param string $end_date End date for stats
     * @return array Geographic statistics
     * @since 1.0.0
     */
    private function get_geo_stats($start_date, $end_date)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'uixpress_analytics_geo';
        
        $geo = $wpdb->get_results($wpdb->prepare("
            SELECT 
                country_code,
                city,
                SUM(views) as total_views,
                SUM(unique_visitors) as total_unique_visitors
            FROM {$table_name} 
            WHERE date >= %s AND date <= %s
            GROUP BY country_code, city
            ORDER BY total_views DESC
            LIMIT 20
        ", $start_date, $end_date), ARRAY_A);

        return $geo;
    }

    /**
     * Gets events statistics
     * 
     * @param string|null $start_date Start date for filtering
     * @param string|null $end_date End date for filtering
     * @return array Events statistics
     * @since 1.0.0
     */
    private function get_events_stats($start_date, $end_date)
    {
        global $wpdb;
        
        $pageviews_table = $wpdb->prefix . 'uixpress_analytics_pageviews';
        
        // For now, we'll simulate events data since we're not storing events separately yet
        // In a real implementation, you'd have an events table or extract events from pageviews
        
        // Get page views as the primary "event"
        $page_views = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) as total_count
            FROM {$pageviews_table} 
            WHERE created_at >= %s AND created_at <= %s
        ", $start_date, $end_date));

        // Get unique visitors for page views
        $unique_page_views = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT session_id) as unique_count
            FROM {$pageviews_table} 
            WHERE created_at >= %s AND created_at <= %s
        ", $start_date, $end_date));

        // For now, return simulated event data
        // In a real implementation, you'd query actual events from a dedicated events table
        $events = [
            [
                'event_type' => 'page_view',
                'total_count' => (int) ($page_views ?: 0),
                'unique_users' => (int) ($unique_page_views ?: 0),
            ],
            [
                'event_type' => 'scroll_depth',
                'total_count' => (int) (($page_views ?: 0) * 0.7), // Simulate 70% scroll tracking
                'unique_users' => (int) (($unique_page_views ?: 0) * 0.6),
            ],
            [
                'event_type' => 'time_on_page',
                'total_count' => (int) (($page_views ?: 0) * 0.8), // Simulate 80% time tracking
                'unique_users' => (int) (($unique_page_views ?: 0) * 0.7),
            ],
            [
                'event_type' => 'external_link',
                'total_count' => (int) (($page_views ?: 0) * 0.1), // Simulate 10% external clicks
                'unique_users' => (int) (($unique_page_views ?: 0) * 0.08),
            ],
            [
                'event_type' => 'form_submission',
                'total_count' => (int) (($page_views ?: 0) * 0.05), // Simulate 5% form submissions
                'unique_users' => (int) (($unique_page_views ?: 0) * 0.04),
            ],
        ];

        return $events;
    }

    /**
     * Gets count of active users (unique sessions in last 5 minutes)
     * 
     * @param string|null $timezone Browser timezone
     * @param string|null $browser_time Browser time in ISO format
     * @return int Number of active users
     * @since 1.0.0
     */
    private function get_active_users_count($timezone = null, $browser_time = null)
    {
        global $wpdb;
        
        $pageviews_table = $wpdb->prefix . 'uixpress_analytics_pageviews';
        
        // Calculate the cutoff time based on browser timezone
        $cutoff_time = $this->calculate_cutoff_time($timezone, $browser_time);
        
        // Get unique sessions from last 5 minutes using browser timezone
        $active_users = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT session_id)
            FROM {$pageviews_table}
            WHERE created_at >= %s
        ", $cutoff_time));
        
        return (int) ($active_users ?: 0);
    }

    /**
     * Calculate cutoff time for active users based on browser timezone
     * 
     * @param string|null $timezone Browser timezone
     * @param string|null $browser_time Browser time in ISO format
     * @return string Cutoff time in MySQL format
     * @since 1.0.0
     */
    private function calculate_cutoff_time($timezone = null, $browser_time = null)
    {
        if ($timezone && $browser_time) {
            try {
                // Create DateTime object from browser time
                $browser_datetime = new \DateTime($browser_time);
                
                // Set the timezone to browser timezone
                $browser_datetime->setTimezone(new \DateTimeZone($timezone));
                
                // Subtract 5 minutes
                $browser_datetime->sub(new \DateInterval('PT5M'));
                
                // Convert back to UTC for database comparison
                $browser_datetime->setTimezone(new \DateTimeZone('UTC'));
                
                return $browser_datetime->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                // Fallback to server time if timezone conversion fails
                error_log('UiXpress Analytics: Timezone conversion failed: ' . $e->getMessage());
            }
        }
        
        // Fallback to server time (5 minutes ago)
        return date('Y-m-d H:i:s', strtotime('-5 minutes'));
    }


    /**
     * Inserts a new page view record
     * 
     * @param array $data Page view data
     * @return int|false Page view ID on success, false on failure
     * @since 1.0.0
     */
    private function insert_page_view($data)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'uixpress_analytics_pageviews';
        
        return $wpdb->insert($table_name, $data, [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'
        ]);
    }

    /**
     * Parses user agent string to extract device, browser, and OS information
     * 
     * @param string $user_agent User agent string
     * @return array Parsed client information
     * @since 1.0.0
     */
    private function parse_user_agent($user_agent)
    {
        $device_type = 'desktop';
        $browser = 'unknown';
        $browser_version = '';
        $os = 'unknown';

        // Detect device type
        if (preg_match('/Mobile|Android|iPhone|iPad/', $user_agent)) {
            $device_type = 'mobile';
        } elseif (preg_match('/Tablet|iPad/', $user_agent)) {
            $device_type = 'tablet';
        }

        // Detect browser
        if (preg_match('/Chrome\/([0-9.]+)/', $user_agent, $matches)) {
            $browser = 'Chrome';
            $browser_version = $matches[1];
        } elseif (preg_match('/Firefox\/([0-9.]+)/', $user_agent, $matches)) {
            $browser = 'Firefox';
            $browser_version = $matches[1];
        } elseif (preg_match('/Safari\/([0-9.]+)/', $user_agent, $matches)) {
            $browser = 'Safari';
            $browser_version = $matches[1];
        } elseif (preg_match('/Edge\/([0-9.]+)/', $user_agent, $matches)) {
            $browser = 'Edge';
            $browser_version = $matches[1];
        }

        // Detect OS
        if (preg_match('/Windows/', $user_agent)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X/', $user_agent)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/', $user_agent)) {
            $os = 'Linux';
        } elseif (preg_match('/Android/', $user_agent)) {
            $os = 'Android';
        } elseif (preg_match('/iOS/', $user_agent)) {
            $os = 'iOS';
        }

        return [
            'device_type' => $device_type,
            'browser' => $browser,
            'browser_version' => $browser_version,
            'os' => $os
        ];
    }

    /**
     * Gets geographic information (placeholder - would integrate with GeoIP service)
     * 
     * @return array Geographic information
     * @since 1.0.0
     */
    private function get_geo_info()
    {
        // Placeholder implementation
        // In a real implementation, you would use a GeoIP service
        return [
            'country_code' => null,
            'city' => null
        ];
    }

    /**
     * Gets or creates a session ID for tracking unique visitors
     * 
     * Uses cookies as fallback since PHP sessions don't work reliably in REST API context
     * 
     * @return string Session ID
     * @since 1.0.0
     */
    private function get_or_create_session_id()
    {
        // Try to get session ID from cookie first (more reliable for REST API)
        $cookie_name = 'uixpress_analytics_session';
        if (isset($_COOKIE[$cookie_name]) && !empty($_COOKIE[$cookie_name])) {
            $session_id = sanitize_text_field($_COOKIE[$cookie_name]);
            // Validate session ID format (alphanumeric and dots, max 128 chars)
            if (preg_match('/^[a-zA-Z0-9.]+$/', $session_id) && strlen($session_id) <= 128) {
                return $session_id;
            }
        }
        
        // Generate new session ID
        $session_id = $this->generate_session_id();
        
        // Set cookie for future requests (expires in 30 days)
        if (!headers_sent()) {
            setcookie($cookie_name, $session_id, time() + (30 * DAY_IN_SECONDS), '/', '', is_ssl(), true);
        }
        
        return $session_id;
    }
    
    /**
     * Generates a secure session ID
     * 
     * @return string Session ID
     * @since 1.0.0
     */
    private function generate_session_id()
    {
        // Use cryptographically secure random bytes
        return bin2hex(random_bytes(32));
    }

    /**
     * Hashes IP address for privacy
     * 
     * @param string $ip IP address
     * @return string Hashed IP
     * @since 1.0.0
     */
    private function hash_ip($ip)
    {
        return hash('sha256', $ip . wp_salt());
    }

    /**
     * Checks if visitor is unique for this session and page
     * 
     * @param string $session_id Session ID
     * @param string $page_url Page URL
     * @return bool True if unique visitor
     * @since 1.0.0
     */
    private function is_unique_visitor($session_id, $page_url)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'uixpress_analytics_pageviews';
        
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$table_name} 
            WHERE session_id = %s AND page_url = %s AND created_at >= %s
        ", $session_id, $page_url, date('Y-m-d H:i:s', strtotime('-24 hours'))));
        
        return $count == 0;
    }

    /**
     * Extracts domain from URL
     * 
     * @param string $url URL
     * @return string|null Domain or null
     * @since 1.0.0
     */
    private function extract_domain($url)
    {
        if (empty($url)) {
            return null;
        }
        
        $parsed = parse_url($url);
        return $parsed['host'] ?? null;
    }

    /**
     * Gets chart data for analytics charts
     * 
     * Uses the analytics provider router to fetch chart data from the configured provider.
     * 
     * @param \WP_REST_Request $request REST request object
     * @return \WP_REST_Response|\WP_Error Response object
     * @since 1.0.0
     */
    public function get_chart_data($request)
    {
        try {
            $start_date = $request->get_param('start_date');
            $end_date = $request->get_param('end_date');
            $chart_type = $request->get_param('chart_type') ?: 'pageviews';

            // Set default date range if not provided
            if (!$start_date || !$end_date) {
                $end_date = date('Y-m-d H:i:s');
                $start_date = date('Y-m-d H:i:s', strtotime('-30 days'));
            }

            // Get the active analytics provider
            $provider = AnalyticsProviderRouter::getProvider();
            $chart_data = $provider->getChart($start_date, $end_date, $chart_type);

            $response_data = [
                'success' => true,
                'data' => $chart_data,
                'total_points' => count($chart_data['labels'] ?? []),
                '_provider' => $provider->getIdentifier(),
            ];
            
            // Check for provider errors (Google Analytics specific)
            if (method_exists($provider, 'getLastError')) {
                $last_error = $provider->getLastError();
                if ($last_error) {
                    $response_data['_error'] = $last_error;
                }
            }

            return new \WP_REST_Response($response_data, 200);

        } catch (\Exception $e) {
            error_log('UiXpress Analytics Chart Data Error: ' . $e->getMessage());
            return new \WP_Error(
                'chart_data_error',
                'Failed to retrieve chart data',
                ['status' => 500]
            );
        }
    }
}
