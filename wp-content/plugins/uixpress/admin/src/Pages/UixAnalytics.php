<?php

namespace UiXpress\Pages;

use UiXpress\Analytics\AnalyticsDatabase;
use UiXpress\Utility\Scripts;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class UixAnalytics
 *
 * Handles loading the analytics tracking script on frontend
 */
class UixAnalytics
{
    /**
     * UixAnalytics constructor.
     */
    public function __construct()
    {
        add_action("wp_footer", [$this, "load_analytics_script"], 1);
    }

    /**
     * Loads the analytics tracking script in the frontend footer
     * Only loads if analytics is enabled in settings
     * 
     * @return void
     * @since 1.0.0
     */
    public function load_analytics_script()
    {
        // Only load if analytics is enabled
        if (!AnalyticsDatabase::is_analytics_enabled()) {
            return;
        }

        // Only load on frontend (not admin)
        if (is_admin()) {
            return;
        }

        // Enqueue WordPress REST API script for frontend
        wp_enqueue_script('wp-api');

        // Get plugin URL and script name
        $url = plugins_url("uixpress/");
        $script_name = Scripts::get_base_script_path("Analytics.js");

        if (!$script_name) {
            return;
        }

        // Setup script object with defer attribute for performance
        $analyticsScript = [
            "id" => "uixpress-analytics-script",
            "src" => $url . "app/dist/{$script_name}",
            "type" => "module",
            "defer" => true,
        ];

        // Print script tag
        wp_print_script_tag($analyticsScript);
    }
}