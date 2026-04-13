<?php

namespace UiXpress\Analytics;

defined("ABSPATH") || exit();

/**
 * Class DummyDataGenerator
 *
 * Generates dummy analytics data for testing purposes
 * 
 * @since 1.0.0
 */
class DummyDataGenerator
{
    private $pageviews_table;
    private $daily_table;
    private $referrers_table;
    private $devices_table;
    private $geo_table;

    /**
     * DummyDataGenerator constructor.
     */
    public function __construct()
    {
        global $wpdb;
        
        $this->pageviews_table = $wpdb->prefix . 'uixpress_analytics_pageviews';
        $this->daily_table = $wpdb->prefix . 'uixpress_analytics_daily';
        $this->referrers_table = $wpdb->prefix . 'uixpress_analytics_referrers';
        $this->devices_table = $wpdb->prefix . 'uixpress_analytics_devices';
        $this->geo_table = $wpdb->prefix . 'uixpress_analytics_geo';
    }

    /**
     * Generate dummy data for the last two months
     * 
     * @param int $months Number of months to generate data for (default: 2)
     * @return void
     * @since 1.0.0
     */
    public function generate_dummy_data($months = 2)
    {
        global $wpdb;

        // Check if analytics is enabled
        if (!AnalyticsDatabase::is_analytics_enabled()) {
            wp_die('Analytics is not enabled. Please enable analytics in settings first.');
        }

        // Clear existing data
        $this->clear_existing_data();

        // Generate data for each day in the specified period
        $start_date = date('Y-m-d', strtotime("-{$months} months"));
        $end_date = date('Y-m-d');

        $current_date = $start_date;
        
        while ($current_date <= $end_date) {
            $this->generate_daily_data($current_date);
            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
        }

        // Run aggregation to populate daily tables
        $this->aggregate_dummy_data();

        echo "Generated dummy analytics data for {$months} months successfully!";
    }

    /**
     * Clear existing analytics data
     * 
     * @return void
     * @since 1.0.0
     */
    private function clear_existing_data()
    {
        global $wpdb;

        $wpdb->query("DELETE FROM {$this->pageviews_table}");
        $wpdb->query("DELETE FROM {$this->daily_table}");
        $wpdb->query("DELETE FROM {$this->referrers_table}");
        $wpdb->query("DELETE FROM {$this->devices_table}");
        $wpdb->query("DELETE FROM {$this->geo_table}");
    }

    /**
     * Generate dummy data for a specific date
     * 
     * @param string $date Date in Y-m-d format
     * @return void
     * @since 1.0.0
     */
    private function generate_daily_data($date)
    {
        global $wpdb;

        // Sample data for realistic analytics
        $pages = [
            '/',
            '/about',
            '/contact',
            '/products',
            '/blog',
            '/services',
            '/pricing',
            '/faq',
            '/support',
            '/news',
            '/gallery',
            '/testimonials'
        ];

        $device_types = ['desktop', 'mobile', 'tablet'];
        $browsers = ['Chrome', 'Safari', 'Firefox', 'Edge'];
        $operating_systems = ['Windows', 'macOS', 'iOS', 'Android', 'Linux'];
        $countries = ['US', 'CA', 'GB', 'AU', 'DE', 'FR', 'IT', 'ES', 'NL', 'SE'];
        $cities = ['New York', 'London', 'Toronto', 'Sydney', 'Berlin', 'Paris', 'Rome', 'Madrid', 'Amsterdam', 'Stockholm'];
        
        $referrers = [
            'google.com',
            'bing.com',
            'yahoo.com',
            'facebook.com',
            'twitter.com',
            'linkedin.com',
            'reddit.com',
            'youtube.com',
            'instagram.com',
            'tiktok.com',
            'direct',
            null
        ];

        // Generate pageviews for this day (10-100 per day)
        $daily_pageviews = rand(10, 100);
        
        for ($i = 0; $i < $daily_pageviews; $i++) {
            $page = $pages[array_rand($pages)];
            $device_type = $device_types[array_rand($device_types)];
            $browser = $browsers[array_rand($browsers)];
            $os = $operating_systems[array_rand($operating_systems)];
            $country = $countries[array_rand($countries)];
            $city = $cities[array_rand($cities)];
            $referrer_domain = $referrers[array_rand($referrers)];
            
            // Generate session ID (some sessions will have multiple pageviews)
            $session_id = 'session_' . $date . '_' . rand(1, 20); // 20 unique sessions per day
            
            // Random time during the day
            $hour = rand(0, 23);
            $minute = rand(0, 59);
            $second = rand(0, 59);
            $created_at = $date . ' ' . sprintf('%02d:%02d:%02d', $hour, $minute, $second);
            
            // Generate page title based on page
            $page_title = $this->generate_page_title($page);
            
            // Generate user agent based on browser and OS
            $user_agent = $this->generate_user_agent($browser, $os, $device_type);
            
            // Generate IP hash (simulated)
            $ip_hash = hash('sha256', $session_id . rand(1, 1000));
            
            // Determine if this is a unique visitor (first visit of the day for this session)
            $is_unique_visitor = rand(1, 100) <= 30; // 30% chance of being unique
            
            $wpdb->insert(
                $this->pageviews_table,
                [
                    'page_url' => home_url($page),
                    'page_title' => $page_title,
                    'referrer' => $referrer_domain ? 'https://' . $referrer_domain . '/search' : null,
                    'referrer_domain' => $referrer_domain,
                    'user_agent' => $user_agent,
                    'device_type' => $device_type,
                    'browser' => $browser,
                    'browser_version' => $this->generate_browser_version($browser),
                    'os' => $os,
                    'country_code' => $country,
                    'city' => $city,
                    'ip_hash' => $ip_hash,
                    'session_id' => $session_id,
                    'is_unique_visitor' => $is_unique_visitor ? 1 : 0,
                    'created_at' => $created_at
                ],
                [
                    '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'
                ]
            );
        }
    }

    /**
     * Generate page title based on page URL
     * 
     * @param string $page Page URL
     * @return string Page title
     * @since 1.0.0
     */
    private function generate_page_title($page)
    {
        $titles = [
            '/' => 'Home - Welcome to Our Website',
            '/about' => 'About Us - Learn More About Our Company',
            '/contact' => 'Contact Us - Get in Touch',
            '/products' => 'Products - Our Amazing Products',
            '/blog' => 'Blog - Latest News and Updates',
            '/services' => 'Services - What We Offer',
            '/pricing' => 'Pricing - Choose Your Plan',
            '/faq' => 'FAQ - Frequently Asked Questions',
            '/support' => 'Support - We\'re Here to Help',
            '/news' => 'News - Latest Updates',
            '/gallery' => 'Gallery - Photo Collection',
            '/testimonials' => 'Testimonials - What Our Customers Say'
        ];

        return $titles[$page] ?? 'Page - ' . ucfirst(trim($page, '/'));
    }

    /**
     * Generate user agent string
     * 
     * @param string $browser Browser name
     * @param string $os Operating system
     * @param string $device_type Device type
     * @return string User agent string
     * @since 1.0.0
     */
    private function generate_user_agent($browser, $os, $device_type)
    {
        $user_agents = [
            'Chrome' => [
                'Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'macOS' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Linux' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ],
            'Safari' => [
                'macOS' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
                'iOS' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1'
            ],
            'Firefox' => [
                'Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'macOS' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0',
                'Linux' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0'
            ],
            'Edge' => [
                'Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'
            ]
        ];

        if ($device_type === 'mobile') {
            return 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';
        } elseif ($device_type === 'tablet') {
            return 'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';
        }

        return $user_agents[$browser][$os] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    }

    /**
     * Generate browser version
     * 
     * @param string $browser Browser name
     * @return string Browser version
     * @since 1.0.0
     */
    private function generate_browser_version($browser)
    {
        $versions = [
            'Chrome' => '120.0.6099.109',
            'Safari' => '17.1',
            'Firefox' => '121.0',
            'Edge' => '120.0.2210.91'
        ];

        return $versions[$browser] ?? '120.0.0.0';
    }

    /**
     * Aggregate dummy data into daily summary tables
     * 
     * @return void
     * @since 1.0.0
     */
    private function aggregate_dummy_data()
    {
        global $wpdb;

        // Get all unique dates from pageviews
        $dates = $wpdb->get_col("SELECT DISTINCT DATE(created_at) as date FROM {$this->pageviews_table} ORDER BY date");

        foreach ($dates as $date) {
            // Aggregate daily data
            $this->aggregate_daily_stats($date);
            
            // Aggregate referrers data
            $this->aggregate_referrers_stats($date);
            
            // Aggregate devices data
            $this->aggregate_devices_stats($date);
            
            // Aggregate geo data
            $this->aggregate_geo_stats($date);
        }
    }

    /**
     * Aggregate daily statistics
     * 
     * @param string $date Date in Y-m-d format
     * @return void
     * @since 1.0.0
     */
    private function aggregate_daily_stats($date)
    {
        global $wpdb;

        $stats = $wpdb->get_results($wpdb->prepare("
            SELECT 
                page_url,
                page_title,
                COUNT(*) as views,
                COUNT(DISTINCT session_id) as unique_visitors,
                AVG(CASE WHEN is_unique_visitor = 1 THEN 1 ELSE 0 END) * 100 as bounce_rate
            FROM {$this->pageviews_table}
            WHERE DATE(created_at) = %s
            GROUP BY page_url, page_title
        ", $date), ARRAY_A);

        foreach ($stats as $stat) {
            $wpdb->replace(
                $this->daily_table,
                [
                    'date' => $date,
                    'page_url' => $stat['page_url'],
                    'page_title' => $stat['page_title'],
                    'views' => (int) $stat['views'],
                    'unique_visitors' => (int) $stat['unique_visitors'],
                    'avg_time_on_page' => rand(30, 300), // Random time between 30 seconds and 5 minutes
                    'bounce_rate' => (float) $stat['bounce_rate'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s']
            );
        }
    }

    /**
     * Aggregate referrers statistics
     * 
     * @param string $date Date in Y-m-d format
     * @return void
     * @since 1.0.0
     */
    private function aggregate_referrers_stats($date)
    {
        global $wpdb;

        $stats = $wpdb->get_results($wpdb->prepare("
            SELECT 
                referrer_domain,
                referrer,
                page_url,
                COUNT(*) as visits,
                COUNT(DISTINCT session_id) as unique_visitors
            FROM {$this->pageviews_table}
            WHERE DATE(created_at) = %s 
            AND referrer_domain IS NOT NULL 
            AND referrer_domain != ''
            GROUP BY referrer_domain, referrer, page_url
        ", $date), ARRAY_A);

        foreach ($stats as $stat) {
            $wpdb->replace(
                $this->referrers_table,
                [
                    'date' => $date,
                    'referrer_domain' => $stat['referrer_domain'],
                    'referrer_url' => $stat['referrer'],
                    'page_url' => $stat['page_url'],
                    'visits' => (int) $stat['visits'],
                    'unique_visitors' => (int) $stat['unique_visitors'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
            );
        }
    }

    /**
     * Aggregate devices statistics
     * 
     * @param string $date Date in Y-m-d format
     * @return void
     * @since 1.0.0
     */
    private function aggregate_devices_stats($date)
    {
        global $wpdb;

        $stats = $wpdb->get_results($wpdb->prepare("
            SELECT 
                device_type,
                browser,
                os,
                COUNT(*) as views,
                COUNT(DISTINCT session_id) as unique_visitors
            FROM {$this->pageviews_table}
            WHERE DATE(created_at) = %s
            GROUP BY device_type, browser, os
        ", $date), ARRAY_A);

        foreach ($stats as $stat) {
            $wpdb->replace(
                $this->devices_table,
                [
                    'date' => $date,
                    'device_type' => $stat['device_type'],
                    'browser' => $stat['browser'],
                    'os' => $stat['os'],
                    'views' => (int) $stat['views'],
                    'unique_visitors' => (int) $stat['unique_visitors'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
            );
        }
    }

    /**
     * Aggregate geo statistics
     * 
     * @param string $date Date in Y-m-d format
     * @return void
     * @since 1.0.0
     */
    private function aggregate_geo_stats($date)
    {
        global $wpdb;

        $stats = $wpdb->get_results($wpdb->prepare("
            SELECT 
                country_code,
                city,
                COUNT(*) as views,
                COUNT(DISTINCT session_id) as unique_visitors
            FROM {$this->pageviews_table}
            WHERE DATE(created_at) = %s
            AND country_code IS NOT NULL
            GROUP BY country_code, city
        ", $date), ARRAY_A);

        foreach ($stats as $stat) {
            $wpdb->replace(
                $this->geo_table,
                [
                    'date' => $date,
                    'country_code' => $stat['country_code'],
                    'city' => $stat['city'],
                    'views' => (int) $stat['views'],
                    'unique_visitors' => (int) $stat['unique_visitors'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%d', '%d', '%s', '%s']
            );
        }
    }
}
