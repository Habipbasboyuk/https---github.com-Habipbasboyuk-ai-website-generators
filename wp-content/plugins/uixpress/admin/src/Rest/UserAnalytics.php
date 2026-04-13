<?php

namespace UiXpress\Rest;

/**
 * User Analytics REST API endpoint
 * 
 * Provides user registration statistics and analytics
 * with date range filtering and efficient caching.
 */
class UserAnalytics
{
    private $namespace = 'uixpress/v1';
    private $base = 'user-analytics';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_routes()
    {
        register_rest_route($this->namespace, '/' . $this->base, [
            'methods' => 'GET',
            'callback' => [$this, 'get_user_analytics'],
            'permission_callback' => [$this, 'get_user_analytics_permissions_check'],
            'args' => [
                'start_date' => [
                    'required' => false,
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'Start date for analytics (ISO 8601)',
                ],
                'end_date' => [
                    'required' => false,
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'End date for analytics (ISO 8601)',
                ],
            ],
        ]);
    }

    /**
     * Check if user has permission to view user analytics
     *
     * @param \WP_REST_Request $request The REST request object
     * @return bool|WP_Error True if user has permission, WP_Error otherwise
     */
    public function get_user_analytics_permissions_check($request)
    {
        return RestPermissionChecker::check_permissions($request, 'list_users');
    }

    /**
     * Get user analytics data with date filtering
     */
    public function get_user_analytics($request)
    {
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        
        // Create cache key with date range
        $cache_key = 'uixpress_user_analytics_' . md5($start_date . $end_date);
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            $cached_data['from_cache'] = true;
            return rest_ensure_response($cached_data);
        }

        // Calculate analytics data
        $analytics = $this->calculate_user_analytics($start_date, $end_date);
        
        // Cache for 1 hour
        set_transient($cache_key, $analytics, HOUR_IN_SECONDS);
        
        $analytics['from_cache'] = false;
        return rest_ensure_response($analytics);
    }

    /**
     * Calculate user analytics data
     */
    private function calculate_user_analytics($start_date = null, $end_date = null)
    {
        global $wpdb;

        // Set default date range if not provided (last 30 days)
        if (!$start_date) {
            $start_date = date('Y-m-d H:i:s', strtotime('-30 days'));
        } else {
            $start_date = date('Y-m-d H:i:s', strtotime($start_date));
        }
        
        if (!$end_date) {
            $end_date = date('Y-m-d H:i:s');
        } else {
            $end_date = date('Y-m-d H:i:s', strtotime($end_date));
        }

        // Get total users
        $total_users = (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->users}
        ");

        // Get users in date range
        $users_in_range = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->users}
            WHERE user_registered >= %s 
            AND user_registered <= %s
        ", $start_date, $end_date));

        // Get daily user registrations for chart
        $daily_registrations = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(user_registered) as date,
                COUNT(*) as count
            FROM {$wpdb->users}
            WHERE user_registered >= %s 
            AND user_registered <= %s
            GROUP BY DATE(user_registered)
            ORDER BY date ASC
        ", $start_date, $end_date));

        // Get user roles breakdown
        $user_roles = $wpdb->get_results("
            SELECT 
                um.meta_value as role,
                COUNT(*) as count
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id 
            AND um.meta_key = '{$wpdb->prefix}capabilities'
            GROUP BY um.meta_value
            ORDER BY count DESC
        ");

        // Get recent users (last 7 days)
        $recent_users = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->users}
            WHERE user_registered >= %s
        ", date('Y-m-d H:i:s', strtotime('-7 days'))));

        // Get users by month (for trend analysis)
        $monthly_users = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE_FORMAT(user_registered, '%Y-%m') as month,
                COUNT(*) as count
            FROM {$wpdb->users}
            WHERE user_registered >= %s 
            AND user_registered <= %s
            GROUP BY DATE_FORMAT(user_registered, '%Y-%m')
            ORDER BY month ASC
        ", $start_date, $end_date));

        // Process chart data
        $chart_data = $this->prepare_chart_data($daily_registrations, $start_date, $end_date);
        
        // Process role data
        $processed_roles = $this->process_user_roles($user_roles);

        return [
            'total_users' => $total_users,
            'users_in_range' => $users_in_range,
            'recent_users' => $recent_users,
            'chart_data' => $chart_data,
            'user_roles' => $processed_roles,
            'monthly_trend' => $monthly_users,
            'date_range' => [
                'start' => $start_date,
                'end' => $end_date,
            ],
            'last_updated' => current_time('mysql'),
        ];
    }

    /**
     * Prepare chart data with proper date formatting
     */
    private function prepare_chart_data($daily_registrations, $start_date, $end_date)
    {
        $labels = [];
        $data = [];
        
        // Create date range
        $start = new \DateTime($start_date);
        $end = new \DateTime($end_date);
        $interval = new \DateInterval('P1D');
        $period = new \DatePeriod($start, $interval, $end);
        
        // Create array of all dates in range
        $date_counts = [];
        foreach ($daily_registrations as $day) {
            $date_counts[$day->date] = (int) $day->count;
        }
        
        // Fill in all dates in range
        foreach ($period as $date) {
            $date_str = $date->format('Y-m-d');
            $labels[] = $date->format('M j');
            $data[] = isset($date_counts[$date_str]) ? $date_counts[$date_str] : 0;
        }
        
        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => __('New Users', 'uixpress'),
                    'data' => $data,
                    'borderColor' => 'rgb(99, 102, 241)', // indigo-500
                    'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
        ];
    }

    /**
     * Process user roles data
     */
    private function process_user_roles($user_roles)
    {
        $processed = [];
        
        foreach ($user_roles as $role_data) {
            if (empty($role_data->role)) {
                continue;
            }
            
            // Unserialize capabilities to get role name
            $capabilities = maybe_unserialize($role_data->role);
            if (is_array($capabilities)) {
                $role_name = array_key_first($capabilities);
                if ($role_name) {
                    $processed[] = [
                        'role' => $role_name,
                        'count' => (int) $role_data->count,
                    ];
                }
            }
        }
        
        return $processed;
    }
}
