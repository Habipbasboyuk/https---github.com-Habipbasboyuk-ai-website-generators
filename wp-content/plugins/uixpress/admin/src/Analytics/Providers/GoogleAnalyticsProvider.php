<?php

namespace UiXpress\Analytics\Providers;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class GoogleAnalyticsProvider
 * 
 * Google Analytics 4 (GA4) provider that fetches data from the Google Analytics Data API.
 * Uses Service Account authentication with JWT for simpler setup.
 * 
 * @package UiXpress\Analytics\Providers
 * @since 1.0.0
 */
class GoogleAnalyticsProvider implements AnalyticsProviderInterface
{
    /**
     * Google Analytics Data API base URL
     */
    private const API_BASE_URL = 'https://analyticsdata.googleapis.com/v1beta';

    /**
     * Google OAuth token URL
     */
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Cache duration for API responses (5 minutes)
     */
    private const CACHE_DURATION = 300;

    /**
     * Cache duration for access token (50 minutes, tokens last 60 min)
     */
    private const TOKEN_CACHE_DURATION = 3000;

    /**
     * @var array Settings containing service account credentials and property ID
     */
    private array $settings;

    /**
     * @var string|null Cached access token
     */
    private ?string $accessToken = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->settings = $this->getSettings();
    }

    /**
     * @inheritDoc
     */
    public function getIdentifier(): string
    {
        return 'google_analytics';
    }

    /**
     * @inheritDoc
     */
    public function getDisplayName(): string
    {
        return __('Google Analytics 4', 'uixpress');
    }

    /**
     * @inheritDoc
     */
    public function isConfigured(): bool
    {
        $has_service_account = !empty($this->settings['google_analytics_service_account']);
        $has_property_id = !empty($this->settings['google_analytics_property_id']);
        
        
        return $has_service_account && $has_property_id;
    }

    /**
     * Get service account credentials from stored JSON
     * 
     * @return array|null Service account credentials or null
     */
    private function getServiceAccountCredentials(): ?array
    {
        $json = $this->settings['google_analytics_service_account'] ?? '';
        if (empty($json)) {
            return null;
        }

        // Decrypt if encrypted
        $decrypted = $this->decryptToken($json);
        $credentials = json_decode($decrypted, true);

        if (!$credentials || !isset($credentials['private_key']) || !isset($credentials['client_email'])) {
            // Try without decryption (in case it's stored plain)
            $credentials = json_decode($json, true);
        }

        return $credentials;
    }

    /**
     * Generate a JWT for service account authentication
     * 
     * @param array $credentials Service account credentials
     * @return string|null JWT token or null on failure
     */
    private function generateJWT(array $credentials): ?string
    {
        $now = time();
        $expiry = $now + 3600; // 1 hour

        // JWT Header
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        // JWT Payload
        $payload = [
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $expiry,
        ];

        // Encode header and payload
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        // Create signature
        $dataToSign = $headerEncoded . '.' . $payloadEncoded;
        $privateKey = $credentials['private_key'];

        $signature = '';
        $success = openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$success) {
            error_log('UiXpress GA: Failed to sign JWT');
            return null;
        }

        $signatureEncoded = $this->base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Base64 URL encode (JWT-safe encoding)
     * 
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Get access token using service account JWT
     * 
     * @return string|null Access token or null
     */
    private function getAccessToken(): ?string
    {
        // Check cached token
        $cachedToken = get_transient('uixpress_ga_access_token');
        if ($cachedToken) {
            return $cachedToken;
        }

        $credentials = $this->getServiceAccountCredentials();
        if (!$credentials) {
            return null;
        }

        $jwt = $this->generateJWT($credentials);
        if (!$jwt) {
            return null;
        }

        // Exchange JWT for access token
        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token'])) {
            // Cache the token
            set_transient('uixpress_ga_access_token', $body['access_token'], self::TOKEN_CACHE_DURATION);
            return $body['access_token'];
        }

        return null;
    }


    /**
     * @inheritDoc
     */
    public function getOverview(string $start_date, string $end_date, ?string $page_url = null): array
    {   

        if (!$this->isConfigured()) {
            return $this->getEmptyOverview();
        }

        $cache_key = 'ga_overview_' . md5($start_date . $end_date . ($page_url ?? ''));
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Build request body
        $request_body = [
            'dateRanges' => [
                ['startDate' => $this->formatDateForGA($start_date), 'endDate' => $this->formatDateForGA($end_date)],
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'activeUsers'],
                ['name' => 'averageSessionDuration'],
                ['name' => 'bounceRate'],
            ],
        ];

        // Add page filter if specified
        if ($page_url) {
            $request_body['dimensionFilter'] = [
                'filter' => [
                    'fieldName' => 'pagePath',
                    'stringFilter' => [
                        'matchType' => 'EXACT',
                        'value' => parse_url($page_url, PHP_URL_PATH) ?: $page_url,
                    ],
                ],
            ];
        }

        $current_data = $this->runReport($request_body);


        // Get comparison period
        $comparison_period = $this->getComparisonPeriod($start_date, $end_date);
        $request_body['dateRanges'] = [
            ['startDate' => $this->formatDateForGA($comparison_period['start']), 'endDate' => $this->formatDateForGA($comparison_period['end'])],
        ];
        $comparison_data = $this->runReport($request_body);

        $result = [
            'total_views' => (int) ($current_data['screenPageViews'] ?? 0),
            'total_unique_visitors' => (int) ($current_data['activeUsers'] ?? 0),
            'avg_time_on_page' => (float) ($current_data['averageSessionDuration'] ?? 0),
            'avg_bounce_rate' => (float) ($current_data['bounceRate'] ?? 0) * 100,
            'unique_pages' => 0, // Would require additional query
            'comparison' => [
                'total_views' => (int) ($comparison_data['screenPageViews'] ?? 0),
                'total_unique_visitors' => (int) ($comparison_data['activeUsers'] ?? 0),
                'avg_time_on_page' => (float) ($comparison_data['averageSessionDuration'] ?? 0),
                'avg_bounce_rate' => (float) ($comparison_data['bounceRate'] ?? 0) * 100,
                'unique_pages' => 0,
                'period' => $comparison_period,
            ],
        ];

        set_transient($cache_key, $result, self::CACHE_DURATION);
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getPages(string $start_date, string $end_date, ?string $page_url = null): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $cache_key = 'ga_pages_' . md5($start_date . $end_date . ($page_url ?? ''));
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $request_body = [
            'dateRanges' => [
                ['startDate' => $this->formatDateForGA($start_date), 'endDate' => $this->formatDateForGA($end_date)],
            ],
            'dimensions' => [
                ['name' => 'pagePath'],
                ['name' => 'pageTitle'],
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'activeUsers'],
                ['name' => 'averageSessionDuration'],
                ['name' => 'bounceRate'],
            ],
            'limit' => 50,
            'orderBys' => [
                ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true],
            ],
        ];

        $response = $this->runReportWithDimensions($request_body);
        
        $pages = [];
        foreach ($response as $row) {
            $pages[] = [
                'page_url' => $row['pagePath'] ?? '',
                'page_title' => $row['pageTitle'] ?? '',
                'total_views' => (int) ($row['screenPageViews'] ?? 0),
                'total_unique_visitors' => (int) ($row['activeUsers'] ?? 0),
                'avg_time_on_page' => (float) ($row['averageSessionDuration'] ?? 0),
                'bounce_rate' => (float) ($row['bounceRate'] ?? 0) * 100,
            ];
        }

        set_transient($cache_key, $pages, self::CACHE_DURATION);
        return $pages;
    }

    /**
     * @inheritDoc
     */
    public function getReferrers(string $start_date, string $end_date): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $cache_key = 'ga_referrers_' . md5($start_date . $end_date);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $request_body = [
            'dateRanges' => [
                ['startDate' => $this->formatDateForGA($start_date), 'endDate' => $this->formatDateForGA($end_date)],
            ],
            'dimensions' => [
                ['name' => 'sessionSource'],
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'activeUsers'],
            ],
            'limit' => 20,
            'orderBys' => [
                ['metric' => ['metricName' => 'sessions'], 'desc' => true],
            ],
        ];

        $response = $this->runReportWithDimensions($request_body);
        
        $referrers = [];
        foreach ($response as $row) {
            $referrers[] = [
                'referrer_domain' => $row['sessionSource'] ?? '(direct)',
                'total_visits' => (int) ($row['sessions'] ?? 0),
                'total_unique_visitors' => (int) ($row['activeUsers'] ?? 0),
            ];
        }

        set_transient($cache_key, $referrers, self::CACHE_DURATION);
        return $referrers;
    }

    /**
     * @inheritDoc
     */
    public function getDevices(string $start_date, string $end_date): array
    {


        if (!$this->isConfigured()) {
            return [];
        }

        

        $cache_key = 'ga_devices_' . md5($start_date . $end_date);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $request_body = [
            'dateRanges' => [
                ['startDate' => $this->formatDateForGA($start_date), 'endDate' => $this->formatDateForGA($end_date)],
            ],
            'dimensions' => [
                ['name' => 'deviceCategory'],
                ['name' => 'browser'],
                ['name' => 'operatingSystem'],
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'activeUsers'],
            ],
            'limit' => 20,
            'orderBys' => [
                ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true],
            ],
        ];

        $response = $this->runReportWithDimensions($request_body);

        file_put_contents('./test.json', json_encode($response, JSON_PRETTY_PRINT));
        
        $devices = [];
        foreach ($response as $row) {
            $devices[] = [
                'device_type' => strtolower($row['deviceCategory'] ?? 'desktop'),
                'browser' => $row['browser'] ?? 'unknown',
                'os' => $row['operatingSystem'] ?? 'unknown',
                'total_views' => (int) ($row['screenPageViews'] ?? 0),
                'total_unique_visitors' => (int) ($row['activeUsers'] ?? 0),
            ];
        }

        set_transient($cache_key, $devices, self::CACHE_DURATION);
        return $devices;
    }

    /**
     * @inheritDoc
     */
    public function getGeo(string $start_date, string $end_date): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $cache_key = 'ga_geo_' . md5($start_date . $end_date);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $request_body = [
            'dateRanges' => [
                ['startDate' => $this->formatDateForGA($start_date), 'endDate' => $this->formatDateForGA($end_date)],
            ],
            'dimensions' => [
                ['name' => 'country'],
                ['name' => 'city'],
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'activeUsers'],
            ],
            'limit' => 20,
            'orderBys' => [
                ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true],
            ],
        ];

        $response = $this->runReportWithDimensions($request_body);
        
        $geo = [];
        foreach ($response as $row) {
            $geo[] = [
                'country_code' => $this->countryNameToCode($row['country'] ?? ''),
                'city' => $row['city'] ?? null,
                'total_views' => (int) ($row['screenPageViews'] ?? 0),
                'total_unique_visitors' => (int) ($row['activeUsers'] ?? 0),
            ];
        }

        set_transient($cache_key, $geo, self::CACHE_DURATION);
        return $geo;
    }

    /**
     * @inheritDoc
     */
    public function getEvents(string $start_date, string $end_date): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $cache_key = 'ga_events_' . md5($start_date . $end_date);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $request_body = [
            'dateRanges' => [
                ['startDate' => $this->formatDateForGA($start_date), 'endDate' => $this->formatDateForGA($end_date)],
            ],
            'dimensions' => [
                ['name' => 'eventName'],
            ],
            'metrics' => [
                ['name' => 'eventCount'],
                ['name' => 'activeUsers'],
            ],
            'limit' => 20,
            'orderBys' => [
                ['metric' => ['metricName' => 'eventCount'], 'desc' => true],
            ],
        ];

        $response = $this->runReportWithDimensions($request_body);
        
        $events = [];
        foreach ($response as $row) {
            $events[] = [
                'event_type' => $row['eventName'] ?? 'unknown',
                'total_count' => (int) ($row['eventCount'] ?? 0),
                'unique_users' => (int) ($row['activeUsers'] ?? 0),
            ];
        }

        set_transient($cache_key, $events, self::CACHE_DURATION);
        return $events;
    }

    /**
     * @inheritDoc
     */
    public function getChart(string $start_date, string $end_date, string $chart_type = 'pageviews'): array
    {
        if (!$this->isConfigured()) {
            return $this->getEmptyChart();
        }

        $cache_key = 'ga_chart_' . md5($start_date . $end_date . $chart_type);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $metrics = [];
        switch ($chart_type) {
            case 'visitors':
                $metrics = [['name' => 'activeUsers']];
                break;
            case 'both':
                $metrics = [['name' => 'screenPageViews'], ['name' => 'activeUsers']];
                break;
            default: // pageviews
                $metrics = [['name' => 'screenPageViews']];
                break;
        }

        $request_body = [
            'dateRanges' => [
                ['startDate' => $this->formatDateForGA($start_date), 'endDate' => $this->formatDateForGA($end_date)],
            ],
            'dimensions' => [
                ['name' => 'date'],
            ],
            'metrics' => $metrics,
            'orderBys' => [
                ['dimension' => ['dimensionName' => 'date'], 'desc' => false],
            ],
        ];

        $response = $this->runReportWithDimensions($request_body);

        if ($chart_type === 'both') {
            $chart_data = [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => 'Page Views',
                        'data' => [],
                        'borderColor' => 'rgb(99, 102, 241)',
                        'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
                        'tension' => 0.4,
                    ],
                    [
                        'label' => 'Unique Visitors',
                        'data' => [],
                        'borderColor' => 'rgb(16, 185, 129)',
                        'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                        'tension' => 0.4,
                    ]
                ]
            ];

            foreach ($response as $row) {
                $chart_data['labels'][] = $this->formatGADateForDisplay($row['date'] ?? '');
                $chart_data['datasets'][0]['data'][] = (int) ($row['screenPageViews'] ?? 0);
                $chart_data['datasets'][1]['data'][] = (int) ($row['activeUsers'] ?? 0);
            }
        } else {
            $metric_key = $chart_type === 'visitors' ? 'activeUsers' : 'screenPageViews';
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

            foreach ($response as $row) {
                $chart_data['labels'][] = $this->formatGADateForDisplay($row['date'] ?? '');
                $chart_data['datasets'][0]['data'][] = (int) ($row[$metric_key] ?? 0);
            }
        }

        set_transient($cache_key, $chart_data, self::CACHE_DURATION);
        return $chart_data;
    }

    /**
     * @inheritDoc
     */
    public function getActiveUsers(?string $timezone = null, ?string $browser_time = null): array
    {
        if (!$this->isConfigured()) {
            return [
                'active_users' => 0,
                'timestamp' => current_time('mysql'),
                'timeframe' => '30 minutes',
            ];
        }

        // GA4 Realtime API endpoint
        $property_id = $this->settings['google_analytics_property_id'];
        $url = self::API_BASE_URL . "/properties/{$property_id}:runRealtimeReport";

        $request_body = [
            'metrics' => [
                ['name' => 'activeUsers'],
            ],
        ];

        $response = $this->makeApiRequest($url, $request_body);

        $active_users = 0;
        if (isset($response['rows'][0]['metricValues'][0]['value'])) {
            $active_users = (int) $response['rows'][0]['metricValues'][0]['value'];
        }

        return [
            'active_users' => $active_users,
            'timestamp' => current_time('mysql'),
            'browser_timezone' => $timezone,
            'browser_time' => $browser_time,
            'timeframe' => '30 minutes', // GA4 realtime is 30 minutes
        ];
    }

    /**
     * Run a GA4 report and return aggregated metrics
     * 
     * @param array $request_body Report request body
     * @return array Aggregated metric values
     */
    private function runReport(array $request_body): array
    {
        $property_id = $this->settings['google_analytics_property_id'];
        $url = self::API_BASE_URL . "/properties/{$property_id}:runReport";

        $response = $this->makeApiRequest($url, $request_body);

        $result = [];
        if (isset($response['rows'][0]['metricValues'])) {
            $metric_headers = $response['metricHeaders'] ?? [];
            foreach ($response['rows'][0]['metricValues'] as $index => $metric) {
                $metric_name = $metric_headers[$index]['name'] ?? "metric_{$index}";
                $result[$metric_name] = $metric['value'] ?? 0;
            }
        }

        return $result;
    }

    /**
     * Run a GA4 report with dimensions and return rows
     * 
     * @param array $request_body Report request body
     * @return array Array of rows with dimension and metric values
     */
    private function runReportWithDimensions(array $request_body): array
    {
        $property_id = $this->settings['google_analytics_property_id'];
        $url = self::API_BASE_URL . "/properties/{$property_id}:runReport";

        $response = $this->makeApiRequest($url, $request_body);

        $rows = [];
        if (isset($response['rows'])) {
            $dimension_headers = array_map(fn($h) => $h['name'], $response['dimensionHeaders'] ?? []);
            $metric_headers = array_map(fn($h) => $h['name'], $response['metricHeaders'] ?? []);

            foreach ($response['rows'] as $row) {
                $row_data = [];
                
                // Map dimension values
                foreach ($row['dimensionValues'] ?? [] as $index => $dim) {
                    $row_data[$dimension_headers[$index] ?? "dimension_{$index}"] = $dim['value'] ?? '';
                }
                
                // Map metric values
                foreach ($row['metricValues'] ?? [] as $index => $metric) {
                    $row_data[$metric_headers[$index] ?? "metric_{$index}"] = $metric['value'] ?? 0;
                }
                
                $rows[] = $row_data;
            }
        }

        return $rows;
    }

    /**
     * Make an API request to Google Analytics
     * 
     * @param string $url API endpoint URL
     * @param array $body Request body
     * @return array Response data
     * @throws \Exception When API returns an error
     */
    private function makeApiRequest(string $url, array $body): array
    {
        $access_token = $this->getValidAccessToken();
        if (!$access_token) {
            $this->setLastError('no_access_token', __('Could not authenticate with Google Analytics. Please check your service account credentials.', 'uixpress'));
            return [];
        }

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->setLastError('network_error', $response->get_error_message());
            return [];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $parsed_body = json_decode($response_body, true);

        if ($status_code !== 200) {
            $error_message = $parsed_body['error']['message'] ?? 'Unknown error';
            $error_status = $parsed_body['error']['status'] ?? 'UNKNOWN';
            
            // Handle specific error codes with user-friendly messages
            if ($status_code === 403 && $error_status === 'PERMISSION_DENIED') {
                $user_message = __('Permission denied: The service account does not have access to this Google Analytics property. Please add the service account email to your GA4 property with Viewer access. Go to Google Analytics → Admin → Property Access Management and add the service account email.', 'uixpress');
                $this->setLastError('permission_denied', $user_message, [
                    'help_url' => 'https://support.google.com/analytics/answer/9305587',
                    'action_required' => 'add_service_account_to_property',
                ]);
            } elseif ($status_code === 401) {
                $this->setLastError('authentication_failed', __('Authentication failed. The access token is invalid or expired. Please try reconnecting your Google Analytics account.', 'uixpress'));
                $this->refreshAccessToken();
            } elseif ($status_code === 404) {
                $this->setLastError('property_not_found', __('The specified Google Analytics property was not found. Please check that the Property ID is correct.', 'uixpress'));
            } else {
                $this->setLastError('api_error', $error_message, ['status_code' => $status_code]);
            }
            
            return [];
        }
        
        // Clear any previous error on success
        $this->clearLastError();

        return $parsed_body ?: [];
    }
    
    /**
     * Store the last error that occurred
     * 
     * @param string $code Error code
     * @param string $message User-friendly error message
     * @param array $extra Additional error data
     */
    private function setLastError(string $code, string $message, array $extra = []): void
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'timestamp' => time(),
        ];
        
        if (!empty($extra)) {
            $error = array_merge($error, $extra);
        }
        
        set_transient('uixpress_ga_last_error', $error, HOUR_IN_SECONDS);
    }
    
    /**
     * Clear the last error
     */
    private function clearLastError(): void
    {
        delete_transient('uixpress_ga_last_error');
    }
    
    /**
     * Get the last error that occurred
     * 
     * @return array|null Error data or null if no error
     */
    public function getLastError(): ?array
    {
        return get_transient('uixpress_ga_last_error') ?: null;
    }

    /**
     * Get a valid access token using service account authentication
     * 
     * @return string|null Access token or null if unavailable
     */
    private function getValidAccessToken(): ?string
    {
        return $this->getAccessToken();
    }

    /**
     * Refresh the access token by clearing the cache and getting a new one
     * 
     * @return string|null New access token or null
     */
    private function refreshAccessToken(): ?string
    {
        delete_transient('uixpress_ga_access_token');
        return $this->getAccessToken();
    }

    /**
     * Get UIXpress settings
     * 
     * @return array Settings array
     */
    private function getSettings(): array
    {
        $settings = get_option('uixpress_settings', []);
        return is_array($settings) ? $settings : [];
    }

    /**
     * Update UIXpress settings
     * 
     * @param array $updates Settings to update
     */
    private function updateSettings(array $updates): void
    {
        $settings = $this->getSettings();
        $settings = array_merge($settings, $updates);
        update_option('uixpress_settings', $settings);
    }

    /**
     * Encrypt a token for storage
     * 
     * @param string $token Token to encrypt
     * @return string Encrypted token
     */
    private function encryptToken(string $token): string
    {
        if (empty($token)) {
            return '';
        }
        
        $key = wp_salt('auth');
        $iv = substr(md5($key), 0, 16);
        
        $encrypted = openssl_encrypt($token, 'AES-256-CBC', $key, 0, $iv);
        return $encrypted !== false ? base64_encode($encrypted) : '';
    }

    /**
     * Decrypt a stored token
     * 
     * @param string $encrypted_token Encrypted token
     * @return string Decrypted token
     */
    private function decryptToken(string $encrypted_token): string
    {
        if (empty($encrypted_token)) {
            return '';
        }
        
        $key = wp_salt('auth');
        $iv = substr(md5($key), 0, 16);
        
        $decrypted = openssl_decrypt(base64_decode($encrypted_token), 'AES-256-CBC', $key, 0, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Format a date string for GA4 API (YYYY-MM-DD)
     * 
     * @param string $date Date string
     * @return string Formatted date
     */
    private function formatDateForGA(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp ? date('Y-m-d', $timestamp) : date('Y-m-d');
    }

    /**
     * Format GA4 date (YYYYMMDD) for display
     * 
     * @param string $ga_date GA4 date string
     * @return string Formatted date (YYYY-MM-DD)
     */
    private function formatGADateForDisplay(string $ga_date): string
    {
        if (strlen($ga_date) === 8) {
            return substr($ga_date, 0, 4) . '-' . substr($ga_date, 4, 2) . '-' . substr($ga_date, 6, 2);
        }
        return $ga_date;
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
        
        $duration = $end->diff($start)->days;
        
        $comparison_end = clone $start;
        $comparison_end->sub(new \DateInterval('P1D'));
        
        $comparison_start = clone $comparison_end;
        $comparison_start->sub(new \DateInterval('P' . $duration . 'D'));
        
        return [
            'start' => $comparison_start->format('Y-m-d H:i:s'),
            'end' => $comparison_end->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Convert country name to ISO code (simplified mapping)
     * 
     * @param string $country_name Country name
     * @return string ISO country code
     */
    private function countryNameToCode(string $country_name): string
    {
        $mapping = [
            'United States' => 'US',
            'United Kingdom' => 'GB',
            'Canada' => 'CA',
            'Australia' => 'AU',
            'Germany' => 'DE',
            'France' => 'FR',
            'Spain' => 'ES',
            'Italy' => 'IT',
            'Netherlands' => 'NL',
            'Brazil' => 'BR',
            'India' => 'IN',
            'Japan' => 'JP',
            'China' => 'CN',
            'Russia' => 'RU',
            'Mexico' => 'MX',
            // Add more as needed
        ];

        return $mapping[$country_name] ?? strtoupper(substr($country_name, 0, 2));
    }

    /**
     * Get empty overview structure
     * 
     * @return array Empty overview data
     */
    private function getEmptyOverview(): array
    {
        return [
            'total_views' => 0,
            'total_unique_visitors' => 0,
            'avg_time_on_page' => 0,
            'avg_bounce_rate' => 0,
            'unique_pages' => 0,
            'comparison' => [
                'total_views' => 0,
                'total_unique_visitors' => 0,
                'avg_time_on_page' => 0,
                'avg_bounce_rate' => 0,
                'unique_pages' => 0,
                'period' => ['start' => '', 'end' => ''],
            ],
        ];
    }

    /**
     * Get empty chart structure
     * 
     * @return array Empty chart data
     */
    private function getEmptyChart(): array
    {
        return [
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'Page Views',
                    'data' => [],
                    'borderColor' => 'rgb(99, 102, 241)',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.1)',
                    'tension' => 0.4,
                ]
            ]
        ];
    }
}
