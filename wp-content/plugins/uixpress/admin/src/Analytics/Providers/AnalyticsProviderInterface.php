<?php

namespace UiXpress\Analytics\Providers;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Interface AnalyticsProviderInterface
 * 
 * Defines the contract for analytics data providers.
 * All analytics providers (UIXpress built-in, Google Analytics, etc.) must implement this interface.
 * 
 * @package UiXpress\Analytics\Providers
 * @since 1.0.0
 */
interface AnalyticsProviderInterface
{
    /**
     * Get overview statistics for the specified date range
     * 
     * @param string $start_date Start date in ISO 8601 format
     * @param string $end_date End date in ISO 8601 format
     * @param string|null $page_url Optional page URL filter
     * @return array Overview statistics including:
     *               - total_views: int
     *               - total_unique_visitors: int
     *               - avg_time_on_page: float
     *               - avg_bounce_rate: float
     *               - unique_pages: int
     *               - comparison: array (same structure for previous period)
     */
    public function getOverview(string $start_date, string $end_date, ?string $page_url = null): array;

    /**
     * Get page-level statistics
     * 
     * @param string $start_date Start date in ISO 8601 format
     * @param string $end_date End date in ISO 8601 format
     * @param string|null $page_url Optional page URL filter
     * @return array Array of page statistics, each containing:
     *               - page_url: string
     *               - page_title: string
     *               - total_views: int
     *               - total_unique_visitors: int
     *               - avg_time_on_page: float
     *               - bounce_rate: float
     */
    public function getPages(string $start_date, string $end_date, ?string $page_url = null): array;

    /**
     * Get referrer statistics
     * 
     * @param string $start_date Start date in ISO 8601 format
     * @param string $end_date End date in ISO 8601 format
     * @return array Array of referrer statistics, each containing:
     *               - referrer_domain: string
     *               - total_visits: int
     *               - total_unique_visitors: int
     */
    public function getReferrers(string $start_date, string $end_date): array;

    /**
     * Get device statistics
     * 
     * @param string $start_date Start date in ISO 8601 format
     * @param string $end_date End date in ISO 8601 format
     * @return array Array of device statistics, each containing:
     *               - device_type: string (desktop, mobile, tablet)
     *               - browser: string
     *               - os: string
     *               - total_views: int
     *               - total_unique_visitors: int
     */
    public function getDevices(string $start_date, string $end_date): array;

    /**
     * Get geographic statistics
     * 
     * @param string $start_date Start date in ISO 8601 format
     * @param string $end_date End date in ISO 8601 format
     * @return array Array of geographic statistics, each containing:
     *               - country_code: string
     *               - city: string|null
     *               - total_views: int
     *               - total_unique_visitors: int
     */
    public function getGeo(string $start_date, string $end_date): array;

    /**
     * Get events statistics
     * 
     * @param string $start_date Start date in ISO 8601 format
     * @param string $end_date End date in ISO 8601 format
     * @return array Array of event statistics, each containing:
     *               - event_type: string
     *               - total_count: int
     *               - unique_users: int
     */
    public function getEvents(string $start_date, string $end_date): array;

    /**
     * Get chart data for visualization
     * 
     * @param string $start_date Start date in ISO 8601 format
     * @param string $end_date End date in ISO 8601 format
     * @param string $chart_type Type of chart data (pageviews, visitors, both)
     * @return array Chart data containing:
     *               - labels: array of date strings
     *               - datasets: array of dataset objects with label, data, colors
     */
    public function getChart(string $start_date, string $end_date, string $chart_type = 'pageviews'): array;

    /**
     * Get count of currently active users
     * 
     * @param string|null $timezone Browser timezone
     * @param string|null $browser_time Browser time in ISO format
     * @return array Active users data containing:
     *               - active_users: int
     *               - timestamp: string
     *               - timeframe: string
     */
    public function getActiveUsers(?string $timezone = null, ?string $browser_time = null): array;

    /**
     * Check if the provider is properly configured and ready to use
     * 
     * @return bool True if provider is configured, false otherwise
     */
    public function isConfigured(): bool;

    /**
     * Get the provider identifier
     * 
     * @return string Provider identifier (e.g., 'uixpress', 'google_analytics')
     */
    public function getIdentifier(): string;

    /**
     * Get the provider display name
     * 
     * @return string Human-readable provider name
     */
    public function getDisplayName(): string;
}
