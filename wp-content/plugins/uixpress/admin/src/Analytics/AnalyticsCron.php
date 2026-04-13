<?php

namespace UiXpress\Analytics;

defined("ABSPATH") || exit();

/**
 * Class AnalyticsCron
 *
 * Handles cron jobs for analytics data aggregation and cleanup
 * 
 * @since 1.0.0
 */
class AnalyticsCron
{
    /**
     * AnalyticsCron constructor.
     */
    public function __construct()
    {
        add_action('init', [$this, 'schedule_cron_jobs']);
        add_action('uixpress_analytics_aggregate', [$this, 'aggregate_analytics_data']);
        add_action('uixpress_analytics_cleanup', [$this, 'cleanup_old_data']);
        
        // Add custom cron interval
        add_filter('cron_schedules', [$this, 'add_custom_cron_intervals']);
    }

    /**
     * Schedule cron jobs if they don't exist
     * 
     * @return void
     * @since 1.0.0
     */
    public function schedule_cron_jobs()
    {
        // Only schedule if analytics is enabled
        if (!AnalyticsDatabase::is_analytics_enabled()) {
            return;
        }

        // Schedule aggregation every 30 minutes
        if (!wp_next_scheduled('uixpress_analytics_aggregate')) {
            wp_schedule_event(time(), 'every_30_minutes', 'uixpress_analytics_aggregate');
        }

        // Schedule cleanup daily at 2 AM
        if (!wp_next_scheduled('uixpress_analytics_cleanup')) {
            wp_schedule_event(time(), 'daily', 'uixpress_analytics_cleanup');
        }
    }

    /**
     * Add custom cron intervals
     * 
     * @param array $schedules Existing cron schedules
     * @return array Modified cron schedules
     * @since 1.0.0
     */
    public function add_custom_cron_intervals($schedules)
    {
        $schedules['every_30_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 Minutes', 'uixpress')
        ];

        return $schedules;
    }

    /**
     * Aggregate raw analytics data into daily summaries
     * 
     * @return void
     * @since 1.0.0
     */
    public function aggregate_analytics_data()
    {
        // Check if analytics is still enabled
        if (!AnalyticsDatabase::is_analytics_enabled()) {
            return;
        }

        global $wpdb;

        try {
            // Get the last aggregation timestamp
            $last_aggregation = $this->get_last_aggregation_time();
            
            // Process data from the last aggregation time
            $this->process_daily_aggregation($last_aggregation);
            $this->process_referrers_aggregation($last_aggregation);
            $this->process_devices_aggregation($last_aggregation);
            $this->process_geo_aggregation($last_aggregation);
            
            // Update last aggregation time
            $this->update_last_aggregation_time();
            
            // Update summary cache
            $this->update_summary_cache();
            
        } catch (\Exception $e) {
            error_log('UiXpress Analytics Aggregation Error: ' . $e->getMessage());
        }
    }

    /**
     * Process daily aggregation from raw pageviews
     * 
     * @param string $last_aggregation Last aggregation timestamp
     * @return void
     * @since 1.0.0
     */
    private function process_daily_aggregation($last_aggregation)
    {
        global $wpdb;

        $pageviews_table = $wpdb->prefix . 'uixpress_analytics_pageviews';
        $daily_table = $wpdb->prefix . 'uixpress_analytics_daily';

        // Get raw data to aggregate - simplified query based on actual table schema
        $raw_data = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(created_at) as date,
                page_url,
                page_title,
                COUNT(*) as views,
                COUNT(DISTINCT session_id) as unique_visitors,
                COUNT(DISTINCT CASE WHEN is_unique_visitor = 1 THEN session_id END) as unique_visitor_count
            FROM {$pageviews_table}
            WHERE created_at > %s
            GROUP BY DATE(created_at), page_url, page_title
        ", $last_aggregation), ARRAY_A);

        // Insert or update daily records
        foreach ($raw_data as $row) {
            // Calculate bounce rate based on single-page sessions
            $bounce_rate = $this->calculate_bounce_rate($row['page_url'], $row['date']);
            
            // For now, set avg_time_on_page to 0 since we don't have time tracking yet
            // This can be enhanced when we add time tracking to the frontend script
            
            $wpdb->replace(
                $daily_table,
                [
                    'date' => $row['date'],
                    'page_url' => $row['page_url'],
                    'page_title' => $row['page_title'],
                    'views' => (int) $row['views'],
                    'unique_visitors' => (int) $row['unique_visitors'],
                    'avg_time_on_page' => 0, // Will be implemented when time tracking is added
                    'bounce_rate' => (float) $bounce_rate,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s']
            );
        }
    }

    /**
     * Process referrers aggregation
     * 
     * @param string $last_aggregation Last aggregation timestamp
     * @return void
     * @since 1.0.0
     */
    private function process_referrers_aggregation($last_aggregation)
    {
        global $wpdb;

        $pageviews_table = $wpdb->prefix . 'uixpress_analytics_pageviews';
        $referrers_table = $wpdb->prefix . 'uixpress_analytics_referrers';

        // Get referrer data to aggregate
        $referrer_data = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(created_at) as date,
                referrer_domain,
                referrer as referrer_url,
                page_url,
                COUNT(*) as visits,
                COUNT(DISTINCT session_id) as unique_visitors
            FROM {$pageviews_table}
            WHERE created_at > %s 
            AND referrer_domain IS NOT NULL 
            AND referrer_domain != ''
            GROUP BY DATE(created_at), referrer_domain, referrer, page_url
        ", $last_aggregation), ARRAY_A);

        // Insert or update referrer records
        foreach ($referrer_data as $row) {
            $wpdb->replace(
                $referrers_table,
                [
                    'date' => $row['date'],
                    'referrer_domain' => $row['referrer_domain'],
                    'referrer_url' => $row['referrer_url'],
                    'page_url' => $row['page_url'],
                    'visits' => (int) $row['visits'],
                    'unique_visitors' => (int) $row['unique_visitors'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
            );
        }
    }

    /**
     * Process devices aggregation
     * 
     * @param string $last_aggregation Last aggregation timestamp
     * @return void
     * @since 1.0.0
     */
    private function process_devices_aggregation($last_aggregation)
    {
        global $wpdb;

        $pageviews_table = $wpdb->prefix . 'uixpress_analytics_pageviews';
        $devices_table = $wpdb->prefix . 'uixpress_analytics_devices';

        // Get device data to aggregate
        $device_data = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(created_at) as date,
                device_type,
                browser,
                os,
                COUNT(*) as views,
                COUNT(DISTINCT session_id) as unique_visitors
            FROM {$pageviews_table}
            WHERE created_at > %s
            GROUP BY DATE(created_at), device_type, browser, os
        ", $last_aggregation), ARRAY_A);

        // Insert or update device records
        foreach ($device_data as $row) {
            $wpdb->replace(
                $devices_table,
                [
                    'date' => $row['date'],
                    'device_type' => $row['device_type'],
                    'browser' => $row['browser'],
                    'os' => $row['os'],
                    'views' => (int) $row['views'],
                    'unique_visitors' => (int) $row['unique_visitors'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
            );
        }
    }

    /**
     * Process geographic aggregation
     * 
     * @param string $last_aggregation Last aggregation timestamp
     * @return void
     * @since 1.0.0
     */
    private function process_geo_aggregation($last_aggregation)
    {
        global $wpdb;

        $pageviews_table = $wpdb->prefix . 'uixpress_analytics_pageviews';
        $geo_table = $wpdb->prefix . 'uixpress_analytics_geo';

        // Get geo data to aggregate
        $geo_data = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(created_at) as date,
                country_code,
                city,
                COUNT(*) as views,
                COUNT(DISTINCT session_id) as unique_visitors
            FROM {$pageviews_table}
            WHERE created_at > %s
            AND country_code IS NOT NULL
            GROUP BY DATE(created_at), country_code, city
        ", $last_aggregation), ARRAY_A);

        // Insert or update geo records
        foreach ($geo_data as $row) {
            $wpdb->replace(
                $geo_table,
                [
                    'date' => $row['date'],
                    'country_code' => $row['country_code'],
                    'city' => $row['city'],
                    'views' => (int) $row['views'],
                    'unique_visitors' => (int) $row['unique_visitors'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%d', '%d', '%s', '%s']
            );
        }
    }

    /**
     * Update summary cache with pre-computed stats
     * 
     * @return void
     * @since 1.0.0
     */
    private function update_summary_cache()
    {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'uixpress_analytics_summary';
        $daily_table = $wpdb->prefix . 'uixpress_analytics_daily';

        // Update 7-day stats
        // Escape table name to prevent SQL injection
        $daily_table_escaped = $wpdb->_escape($daily_table);
        $stats_7d = $wpdb->get_row("
            SELECT 
                SUM(views) as total_views,
                SUM(unique_visitors) as total_unique_visitors,
                COUNT(DISTINCT page_url) as unique_pages
            FROM `{$daily_table_escaped}` 
            WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ", ARRAY_A);

        $wpdb->replace(
            $summary_table,
            [
                'stat_key' => 'overview_7d',
                'stat_period' => '7d',
                'stat_value' => json_encode($stats_7d),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s']
        );

        // Update 30-day stats
        $stats_30d = $wpdb->get_row("
            SELECT 
                SUM(views) as total_views,
                SUM(unique_visitors) as total_unique_visitors,
                COUNT(DISTINCT page_url) as unique_pages
            FROM `{$daily_table_escaped}` 
            WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ", ARRAY_A);

        $wpdb->replace(
            $summary_table,
            [
                'stat_key' => 'overview_30d',
                'stat_period' => '30d',
                'stat_value' => json_encode($stats_30d),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s']
        );

        // Update all-time stats
        $stats_all = $wpdb->get_row("
            SELECT 
                SUM(views) as total_views,
                SUM(unique_visitors) as total_unique_visitors,
                COUNT(DISTINCT page_url) as unique_pages
            FROM `{$daily_table_escaped}`
        ", ARRAY_A);

        $wpdb->replace(
            $summary_table,
            [
                'stat_key' => 'overview_all_time',
                'stat_period' => 'all_time',
                'stat_value' => json_encode($stats_all),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s']
        );
    }

    /**
     * Cleanup old raw data based on retention settings
     * 
     * @return void
     * @since 1.0.0
     */
    public function cleanup_old_data()
    {
        // Check if analytics is still enabled
        if (!AnalyticsDatabase::is_analytics_enabled()) {
            return;
        }

        global $wpdb;

        try {
            $retention_days = $this->get_retention_days();
            
            $pageviews_table = $wpdb->prefix . 'uixpress_analytics_pageviews';
            
            // Delete old raw pageview data
            $wpdb->query($wpdb->prepare("
                DELETE FROM {$pageviews_table} 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
            ", $retention_days));
            
            // Update last cleanup time
            $this->update_last_cleanup_time();
            
        } catch (\Exception $e) {
            error_log('UiXpress Analytics Cleanup Error: ' . $e->getMessage());
        }
    }

    /**
     * Get last aggregation timestamp
     * 
     * @return string Last aggregation timestamp
     * @since 1.0.0
     */
    private function get_last_aggregation_time()
    {
        global $wpdb;
        
        $settings_table = $wpdb->prefix . 'uixpress_analytics_settings';
        
        $result = $wpdb->get_var($wpdb->prepare("
            SELECT setting_value 
            FROM {$settings_table} 
            WHERE setting_key = %s
        ", 'last_aggregation'));
        
        return $result ?: date('Y-m-d H:i:s', strtotime('-1 day'));
    }

    /**
     * Update last aggregation timestamp
     * 
     * @return void
     * @since 1.0.0
     */
    private function update_last_aggregation_time()
    {
        global $wpdb;
        
        $settings_table = $wpdb->prefix . 'uixpress_analytics_settings';
        
        $wpdb->replace(
            $settings_table,
            [
                'setting_key' => 'last_aggregation',
                'setting_value' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s']
        );
    }

    /**
     * Update last cleanup timestamp
     * 
     * @return void
     * @since 1.0.0
     */
    private function update_last_cleanup_time()
    {
        global $wpdb;
        
        $settings_table = $wpdb->prefix . 'uixpress_analytics_settings';
        
        $wpdb->replace(
            $settings_table,
            [
                'setting_key' => 'last_cleanup',
                'setting_value' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s']
        );
    }

    /**
     * Get retention days setting
     * 
     * @return int Retention days
     * @since 1.0.0
     */
    private function get_retention_days()
    {
        global $wpdb;
        
        $settings_table = $wpdb->prefix . 'uixpress_analytics_settings';
        
        $result = $wpdb->get_var($wpdb->prepare("
            SELECT setting_value 
            FROM {$settings_table} 
            WHERE setting_key = %s
        ", 'retention_days'));
        
        return (int) ($result ?: 30);
    }

    /**
     * Calculate bounce rate for a specific page and date
     * 
     * @param string $page_url The page URL
     * @param string $date The date
     * @return float Bounce rate percentage
     * @since 1.0.0
     */
    private function calculate_bounce_rate($page_url, $date)
    {
        global $wpdb;
        
        $pageviews_table = $wpdb->prefix . 'uixpress_analytics_pageviews';
        
        // Get sessions that only visited this page (bounced)
        $bounced_sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT session_id)
            FROM {$pageviews_table}
            WHERE DATE(created_at) = %s
            AND page_url = %s
            AND session_id IN (
                SELECT session_id
                FROM {$pageviews_table}
                WHERE DATE(created_at) = %s
                GROUP BY session_id
                HAVING COUNT(DISTINCT page_url) = 1
            )
        ", $date, $page_url, $date));
        
        // Get total sessions for this page
        $total_sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT session_id)
            FROM {$pageviews_table}
            WHERE DATE(created_at) = %s
            AND page_url = %s
        ", $date, $page_url));
        
        if ($total_sessions == 0) {
            return 0.0;
        }
        
        return round(($bounced_sessions / $total_sessions) * 100, 2);
    }

    /**
     * Unschedule cron jobs when analytics is disabled
     * 
     * @return void
     * @since 1.0.0
     */
    public static function unschedule_cron_jobs()
    {
        wp_clear_scheduled_hook('uixpress_analytics_aggregate');
        wp_clear_scheduled_hook('uixpress_analytics_cleanup');
    }
}
