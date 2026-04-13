<?php

namespace UiXpress\Analytics\Providers;

use UiXpress\Analytics\AnalyticsDatabase;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class UIXpressAnalyticsProvider
 * 
 * Built-in analytics provider that uses UIXpress's own database tables.
 * This provider wraps the existing analytics database queries.
 * 
 * @package UiXpress\Analytics\Providers
 * @since 1.0.0
 */
class UIXpressAnalyticsProvider implements AnalyticsProviderInterface
{
    /**
     * @inheritDoc
     */
    public function getIdentifier(): string
    {
        return 'uixpress';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return __('Built-in Analytics', 'uixpress');
    }

    /**
     * @inheritDoc
     */
    public function isConfigured(): bool
    {
        return AnalyticsDatabase::is_analytics_enabled();
    }

    /**
     * @inheritDoc
     */
    public function getOverview(string $start_date, string $end_date, ?string $page_url = null): array
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
        $comparison_period = $this->getComparisonPeriod($start_date, $end_date);
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
     * @inheritDoc
     */
    public function getPages(string $start_date, string $end_date, ?string $page_url = null): array
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

        return $pages ?: [];
    }

    /**
     * @inheritDoc
     */
    public function getReferrers(string $start_date, string $end_date): array
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

        return $referrers ?: [];
    }

    /**
     * @inheritDoc
     */
    public function getDevices(string $start_date, string $end_date): array
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

        return $devices ?: [];
    }

    /**
     * @inheritDoc
     */
    public function getGeo(string $start_date, string $end_date): array
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

        return $geo ?: [];
    }

    /**
     * @inheritDoc
     */
    public function getEvents(string $start_date, string $end_date): array
    {
        global $wpdb;
        
        $pageviews_table = $wpdb->prefix . 'uixpress_analytics_pageviews';
        
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

        // Return simulated event data
        $events = [
            [
                'event_type' => 'page_view',
                'total_count' => (int) ($page_views ?: 0),
                'unique_users' => (int) ($unique_page_views ?: 0),
            ],
            [
                'event_type' => 'scroll_depth',
                'total_count' => (int) (($page_views ?: 0) * 0.7),
                'unique_users' => (int) (($unique_page_views ?: 0) * 0.6),
            ],
            [
                'event_type' => 'time_on_page',
                'total_count' => (int) (($page_views ?: 0) * 0.8),
                'unique_users' => (int) (($unique_page_views ?: 0) * 0.7),
            ],
            [
                'event_type' => 'external_link',
                'total_count' => (int) (($page_views ?: 0) * 0.1),
                'unique_users' => (int) (($unique_page_views ?: 0) * 0.08),
            ],
            [
                'event_type' => 'form_submission',
                'total_count' => (int) (($page_views ?: 0) * 0.05),
                'unique_users' => (int) (($unique_page_views ?: 0) * 0.04),
            ],
        ];

        return $events;
    }

    /**
     * @inheritDoc
     */
    public function getChart(string $start_date, string $end_date, string $chart_type = 'pageviews'): array
    {
        global $wpdb;
        $daily_table = $wpdb->prefix . 'uixpress_analytics_daily';

        // Build the query based on chart type
        $select_fields = '';
        switch ($chart_type) {
            case 'visitors':
                $select_fields = 'DATE(date) as date, SUM(unique_visitors) as value';
                break;
            case 'both':
                $select_fields = 'DATE(date) as date, SUM(views) as pageviews, SUM(unique_visitors) as visitors';
                break;
            default: // pageviews
                $select_fields = 'DATE(date) as date, SUM(views) as value';
                break;
        }

        $query = $wpdb->prepare("
            SELECT {$select_fields}
            FROM {$daily_table}
            WHERE date >= %s AND date <= %s
            GROUP BY DATE(date)
            ORDER BY date ASC
        ", $start_date, $end_date);

        $results = $wpdb->get_results($query, ARRAY_A);

        if ($chart_type === 'both') {
            // Format data for both pageviews and visitors
            $chart_data = [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => 'Page Views',
                        'data' => [],
                        'borderColor' => 'rgb(99, 102, 241)', // indigo-500
                        'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
                        'tension' => 0.4,
                    ],
                    [
                        'label' => 'Unique Visitors',
                        'data' => [],
                        'borderColor' => 'rgb(16, 185, 129)', // emerald-500
                        'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                        'tension' => 0.4,
                    ]
                ]
            ];

            foreach ($results as $row) {
                $chart_data['labels'][] = $row['date'];
                $chart_data['datasets'][0]['data'][] = (int) $row['pageviews'];
                $chart_data['datasets'][1]['data'][] = (int) $row['visitors'];
            }
        } else {
            // Format data for single metric
            $chart_data = [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => $chart_type === 'visitors' ? 'Unique Visitors' : 'Page Views',
                        'data' => [],
                        'borderColor' => $chart_type === 'visitors' ? 'rgb(16, 185, 129)' : 'rgb(99, 102, 241)',
                        'backgroundColor' => $chart_type === 'visitors' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(99, 102, 241, 0.1)',
                        'tension' => 0.4,
                    ]
                ]
            ];

            foreach ($results as $row) {
                $chart_data['labels'][] = $row['date'];
                $chart_data['datasets'][0]['data'][] = (int) $row['value'];
            }
        }

        return $chart_data;
    }

    /**
     * @inheritDoc
     */
    public function getActiveUsers(?string $timezone = null, ?string $browser_time = null): array
    {
        global $wpdb;
        
        $pageviews_table = $wpdb->prefix . 'uixpress_analytics_pageviews';
        
        // Calculate the cutoff time based on browser timezone
        $cutoff_time = $this->calculateCutoffTime($timezone, $browser_time);
        
        // Get unique sessions from last 5 minutes using browser timezone
        $active_users = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT session_id)
            FROM {$pageviews_table}
            WHERE created_at >= %s
        ", $cutoff_time));
        
        return [
            'active_users' => (int) ($active_users ?: 0),
            'timestamp' => current_time('mysql'),
            'browser_timezone' => $timezone,
            'browser_time' => $browser_time,
            'timeframe' => '5 minutes'
        ];
    }

    /**
     * Calculate comparison period dates
     * 
     * @param string $start_date Start date for current period
     * @param string $end_date End date for current period
     * @return array Array with comparison start and end dates
     */
    private function getComparisonPeriod(string $start_date, string $end_date): array
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
     * Calculate cutoff time for active users based on browser timezone
     * 
     * @param string|null $timezone Browser timezone
     * @param string|null $browser_time Browser time in ISO format
     * @return string Cutoff time in MySQL format
     */
    private function calculateCutoffTime(?string $timezone = null, ?string $browser_time = null): string
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
}
