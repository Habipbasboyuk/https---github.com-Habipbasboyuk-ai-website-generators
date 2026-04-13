<?php

namespace UiXpress\Analytics;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class AnalyticsDatabase
 * 
 * Handles database schema creation and management for UiXpress Analytics
 * Only initializes when analytics is enabled in settings
 */
class AnalyticsDatabase
{
    /**
     * AnalyticsDatabase constructor.
     */
    public function __construct()
    {
        add_action('admin_init', [$this, 'maybe_initialize_analytics_tables']);
        add_action('admin_init', [$this, 'maybe_cleanup_analytics_tables']);
    }

    /**
     * Checks if analytics is enabled and initializes tables if needed
     * 
     * @return void
     * @since 1.0.0
     */
    public function maybe_initialize_analytics_tables()
    {
        $settings = get_option('uixpress_settings', []);
        
        if (!isset($settings['enable_uixpress_analytics']) || !$settings['enable_uixpress_analytics']) {
            return;
        }

        if (!$this->tables_exist()) {
            $this->create_analytics_tables();
        }
    }

    /**
     * Removes analytics tables if analytics is disabled
     * 
     * @return void
     * @since 1.0.0
     */
    public function maybe_cleanup_analytics_tables()
    {
        $settings = get_option('uixpress_settings', []);
        
        if (!isset($settings['enable_uixpress_analytics']) || !$settings['enable_uixpress_analytics']) {
            // Analytics is disabled, but we'll keep tables for now
            // In the future, you might want to add a cleanup option here
            return;
        }
    }

    /**
     * Checks if analytics tables exist
     * 
     * @return bool True if all tables exist, false otherwise
     * @since 1.0.0
     */
    private function tables_exist()
    {
        global $wpdb;
        
        $tables = [
            'wp_uixpress_analytics_pageviews',
            'wp_uixpress_analytics_daily',
            'wp_uixpress_analytics_referrers',
            'wp_uixpress_analytics_devices',
            'wp_uixpress_analytics_geo',
            'wp_uixpress_analytics_summary',
            'wp_uixpress_analytics_settings'
        ];

        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . str_replace('wp_', '', $table);
            // Use prepared statement to prevent SQL injection
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
            if (!$table_exists) {
                return false;
            }
        }

        return true;
    }

    /**
     * Creates all analytics tables with the specified schema
     * 
     * @return bool True on success, false on failure
     * @since 1.0.0
     */
    private function create_analytics_tables()
    {
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create pageviews table
        $sql_pageviews = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}uixpress_analytics_pageviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            page_url VARCHAR(500) NOT NULL,
            page_title VARCHAR(255) DEFAULT NULL,
            referrer VARCHAR(500) DEFAULT NULL,
            referrer_domain VARCHAR(255) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            device_type VARCHAR(20) DEFAULT NULL,
            browser VARCHAR(50) DEFAULT NULL,
            browser_version VARCHAR(20) DEFAULT NULL,
            os VARCHAR(50) DEFAULT NULL,
            country_code CHAR(2) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            ip_hash VARCHAR(64) DEFAULT NULL,
            session_id VARCHAR(64) DEFAULT NULL,
            is_unique_visitor TINYINT(1) DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_created_at (created_at),
            INDEX idx_page_url (page_url(255)),
            INDEX idx_session_date (session_id, created_at),
            INDEX idx_referrer_domain (referrer_domain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // Create daily aggregated stats table
        $sql_daily = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}uixpress_analytics_daily (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            page_url VARCHAR(500) NOT NULL,
            page_title VARCHAR(255) DEFAULT NULL,
            views INT UNSIGNED DEFAULT 0,
            unique_visitors INT UNSIGNED DEFAULT 0,
            avg_time_on_page INT UNSIGNED DEFAULT 0,
            bounce_rate DECIMAL(5,2) DEFAULT 0.00,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY unique_date_page (date, page_url(255)),
            INDEX idx_date (date),
            INDEX idx_page_url (page_url(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // Create referrer stats table
        $sql_referrers = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}uixpress_analytics_referrers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            referrer_domain VARCHAR(255) NOT NULL,
            referrer_url VARCHAR(500) DEFAULT NULL,
            page_url VARCHAR(500) NOT NULL,
            visits INT UNSIGNED DEFAULT 0,
            unique_visitors INT UNSIGNED DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY unique_date_referrer_page (date, referrer_domain, page_url(255)),
            INDEX idx_date (date),
            INDEX idx_referrer_domain (referrer_domain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // Create device & browser stats table
        $sql_devices = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}uixpress_analytics_devices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            device_type VARCHAR(20) NOT NULL,
            browser VARCHAR(50) DEFAULT NULL,
            os VARCHAR(50) DEFAULT NULL,
            views INT UNSIGNED DEFAULT 0,
            unique_visitors INT UNSIGNED DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY unique_date_device (date, device_type, browser, os),
            INDEX idx_date (date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // Create geographic stats table
        $sql_geo = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}uixpress_analytics_geo (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            country_code CHAR(2) NOT NULL,
            city VARCHAR(100) DEFAULT NULL,
            views INT UNSIGNED DEFAULT 0,
            unique_visitors INT UNSIGNED DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY unique_date_location (date, country_code, city),
            INDEX idx_date (date),
            INDEX idx_country (country_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // Create summary stats cache table
        $sql_summary = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}uixpress_analytics_summary (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stat_key VARCHAR(100) NOT NULL,
            stat_period VARCHAR(20) NOT NULL,
            stat_value LONGTEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY unique_stat (stat_key, stat_period),
            INDEX idx_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // Create analytics settings table
        $sql_settings = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}uixpress_analytics_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value LONGTEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // Execute table creation
        dbDelta($sql_pageviews);
        dbDelta($sql_daily);
        dbDelta($sql_referrers);
        dbDelta($sql_devices);
        dbDelta($sql_geo);
        dbDelta($sql_summary);
        dbDelta($sql_settings);

        // Insert default settings
        $this->insert_default_settings();

        return true;
    }

    /**
     * Inserts default analytics settings
     * 
     * @return void
     * @since 1.0.0
     */
    private function insert_default_settings()
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'uixpress_analytics_settings';
        $current_time = current_time('mysql');
        
        $default_settings = [
            'retention_days' => '30',
            'track_logged_in_users' => '0',
            'anonymize_ips' => '1',
            'exclude_bots' => '1',
            'last_cleanup' => null,
            'last_aggregation' => null
        ];

        foreach ($default_settings as $key => $value) {
            $wpdb->replace(
                $table_name,
                [
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'updated_at' => $current_time
                ],
                ['%s', '%s', '%s']
            );
        }
    }

    /**
     * Gets a specific analytics setting
     * 
     * @param string $setting_key The setting key to retrieve
     * @param mixed $default_value Default value if setting doesn't exist
     * @return mixed The setting value or default value
     * @since 1.0.0
     */
    public static function get_setting($setting_key, $default_value = null)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'uixpress_analytics_settings';
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$table_name} WHERE setting_key = %s",
            $setting_key
        ));
        
        return $result !== null ? $result : $default_value;
    }

    /**
     * Updates a specific analytics setting
     * 
     * @param string $setting_key The setting key to update
     * @param mixed $setting_value The new setting value
     * @return bool True on success, false on failure
     * @since 1.0.0
     */
    public static function update_setting($setting_key, $setting_value)
    {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'uixpress_analytics_settings';
        $current_time = current_time('mysql');
        
        return $wpdb->replace(
            $table_name,
            [
                'setting_key' => $setting_key,
                'setting_value' => $setting_value,
                'updated_at' => $current_time
            ],
            ['%s', '%s', '%s']
        ) !== false;
    }

    /**
     * Checks if analytics is enabled in plugin settings
     * 
     * @return bool True if analytics is enabled, false otherwise
     * @since 1.0.0
     */
    public static function is_analytics_enabled()
    {
        $settings = get_option('uixpress_settings', []);
        return isset($settings['enable_uixpress_analytics']) && $settings['enable_uixpress_analytics'];
    }

    /**
     * Handle analytics enable/disable
     * 
     * @param bool $enabled Whether analytics should be enabled
     * @return void
     * @since 1.0.0
     */
    public static function toggle_analytics($enabled)
    {
        if ($enabled) {
            // Analytics is being enabled - create tables if needed
            $instance = new self();
            $instance->maybe_create_tables();
        } else {
            // Analytics is being disabled - unschedule cron jobs
            \UiXpress\Analytics\AnalyticsCron::unschedule_cron_jobs();
        }
    }
}
