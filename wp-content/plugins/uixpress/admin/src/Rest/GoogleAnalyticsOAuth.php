<?php

namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class GoogleAnalyticsOAuth
 * 
 * Handles Google Analytics Service Account authentication and related REST API endpoints.
 * Uses Service Account JSON key for simpler authentication without OAuth consent flow.
 * 
 * @package UiXpress\Rest
 * @since 1.0.0
 */
class GoogleAnalyticsOAuth
{
    /**
     * Google Analytics Admin API URL for listing properties
     */
    private const GA_ADMIN_API_URL = 'https://analyticsadmin.googleapis.com/v1beta';

    /**
     * Google OAuth token URL
     */
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Constructor
     */
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void
    {
        // Save service account credentials
        register_rest_route('uixpress/v1', '/google-analytics/credentials', [
            'methods' => 'POST',
            'callback' => [$this, 'save_credentials'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args' => [
                'service_account_json' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        // Disconnect Google Analytics
        register_rest_route('uixpress/v1', '/google-analytics/disconnect', [
            'methods' => 'POST',
            'callback' => [$this, 'disconnect'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        // Get available GA4 properties
        register_rest_route('uixpress/v1', '/google-analytics/properties', [
            'methods' => 'GET',
            'callback' => [$this, 'get_properties'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        // Check connection status
        register_rest_route('uixpress/v1', '/google-analytics/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_status'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        // Save selected property
        register_rest_route('uixpress/v1', '/google-analytics/property', [
            'methods' => 'POST',
            'callback' => [$this, 'save_property'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args' => [
                'property_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Validate credentials
        register_rest_route('uixpress/v1', '/google-analytics/validate', [
            'methods' => 'POST',
            'callback' => [$this, 'validate_credentials'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args' => [
                'service_account_json' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);
    }

    /**
     * Check if user has admin permissions
     * 
     * @param \WP_REST_Request $request The REST request
     * @return bool|\WP_Error
     */
    public function check_admin_permissions($request)
    {
        return RestPermissionChecker::check_permissions($request, 'manage_options');
    }

    /**
     * Validate service account credentials without saving
     * 
     * @param \WP_REST_Request $request The REST request
     * @return \WP_REST_Response|\WP_Error
     */
    public function validate_credentials($request)
    {
        $json = $request->get_param('service_account_json');

        // Parse and validate JSON
        $credentials = json_decode($json, true);
        if (!$credentials) {
            return new \WP_Error('invalid_json', __('Invalid JSON format', 'uixpress'), ['status' => 400]);
        }

        // Check required fields
        $required_fields = ['type', 'project_id', 'private_key', 'client_email'];
        foreach ($required_fields as $field) {
            if (empty($credentials[$field])) {
                return new \WP_Error('missing_field', sprintf(__('Missing required field: %s', 'uixpress'), $field), ['status' => 400]);
            }
        }

        if ($credentials['type'] !== 'service_account') {
            return new \WP_Error('invalid_type', __('JSON must be a service account key (type should be "service_account")', 'uixpress'), ['status' => 400]);
        }

        // Try to get an access token to validate the credentials
        $accessToken = $this->getAccessTokenFromCredentials($credentials);
        if (!$accessToken) {
            return new \WP_Error('auth_failed', __('Failed to authenticate with Google. Please check your service account key.', 'uixpress'), ['status' => 400]);
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Credentials are valid', 'uixpress'),
            'client_email' => $credentials['client_email'],
            'project_id' => $credentials['project_id'],
        ], 200);
    }

    /**
     * Save service account credentials
     * 
     * @param \WP_REST_Request $request The REST request
     * @return \WP_REST_Response|\WP_Error
     */
    public function save_credentials($request)
    {
        $json = $request->get_param('service_account_json');

        // Parse and validate JSON
        $credentials = json_decode($json, true);
        if (!$credentials) {
            return new \WP_Error('invalid_json', __('Invalid JSON format', 'uixpress'), ['status' => 400]);
        }

        // Check required fields
        $required_fields = ['type', 'project_id', 'private_key', 'client_email'];
        foreach ($required_fields as $field) {
            if (empty($credentials[$field])) {
                return new \WP_Error('missing_field', sprintf(__('Missing required field: %s', 'uixpress'), $field), ['status' => 400]);
            }
        }

        if ($credentials['type'] !== 'service_account') {
            return new \WP_Error('invalid_type', __('JSON must be a service account key', 'uixpress'), ['status' => 400]);
        }

        // Try to get an access token to validate the credentials
        $accessToken = $this->getAccessTokenFromCredentials($credentials);
        if (!$accessToken) {
            return new \WP_Error('auth_failed', __('Failed to authenticate with Google. Please check your service account key.', 'uixpress'), ['status' => 400]);
        }

        // Store credentials (encrypted)
        $this->updateSettings([
            'google_analytics_service_account' => $this->encryptToken($json),
        ]);

        // Clear any cached tokens
        delete_transient('uixpress_ga_access_token');

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Service account credentials saved successfully', 'uixpress'),
            'client_email' => $credentials['client_email'],
        ], 200);
    }

    /**
     * Disconnect Google Analytics
     * 
     * @param \WP_REST_Request $request The REST request
     * @return \WP_REST_Response
     */
    public function disconnect($request)
    {
        // Clear all GA-related settings
        $this->updateSettings([
            'google_analytics_service_account' => '',
            'google_analytics_property_id' => '',
        ]);

        // Clear cached tokens
        delete_transient('uixpress_ga_access_token');

        // Clear any cached data
        $this->clearGACache();

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Google Analytics disconnected', 'uixpress'),
        ], 200);
    }

    /**
     * Get available GA4 properties
     * 
     * @param \WP_REST_Request $request The REST request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_properties($request)
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return new \WP_Error('not_connected', __('Not connected to Google Analytics', 'uixpress'), ['status' => 401]);
        }

        // Get account summaries
        $response = wp_remote_get(self::GA_ADMIN_API_URL . '/accountSummaries', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', $response->get_error_message(), ['status' => 500]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_message = $body['error']['message'] ?? __('Failed to fetch properties. Make sure the service account has been added to your GA4 property.', 'uixpress');
            return new \WP_Error('api_error', $error_message, ['status' => $status_code]);
        }

        // Extract properties from account summaries
        $properties = [];
        foreach ($body['accountSummaries'] ?? [] as $account) {
            foreach ($account['propertySummaries'] ?? [] as $property) {
                $property_id = str_replace('properties/', '', $property['property'] ?? '');
                $properties[] = [
                    'id' => $property_id,
                    'name' => $property['displayName'] ?? $property_id,
                    'account' => $account['displayName'] ?? '',
                ];
            }
        }

        // Get current selected property
        $settings = $this->getSettings();
        $selected_property = $settings['google_analytics_property_id'] ?? '';

        return new \WP_REST_Response([
            'success' => true,
            'properties' => $properties,
            'selected' => $selected_property,
        ], 200);
    }

    /**
     * Get connection status
     * 
     * @param \WP_REST_Request $request The REST request
     * @return \WP_REST_Response
     */
    public function get_status($request)
    {
        $settings = $this->getSettings();
        
        $has_credentials = !empty($settings['google_analytics_service_account']);
        $has_property = !empty($settings['google_analytics_property_id']);
        
        // Get service account email if connected
        $client_email = '';
        if ($has_credentials) {
            $json = $this->decryptToken($settings['google_analytics_service_account']);
            $credentials = json_decode($json, true);
            $client_email = $credentials['client_email'] ?? '';
        }

        return new \WP_REST_Response([
            'success' => true,
            'connected' => $has_credentials,
            'configured' => $has_credentials && $has_property,
            'property_id' => $settings['google_analytics_property_id'] ?? '',
            'client_email' => $client_email,
        ], 200);
    }

    /**
     * Save selected property
     * 
     * @param \WP_REST_Request $request The REST request
     * @return \WP_REST_Response|\WP_Error
     */
    public function save_property($request)
    {
        $property_id = $request->get_param('property_id');

        if (empty($property_id)) {
            return new \WP_Error('missing_property', __('Property ID is required', 'uixpress'), ['status' => 400]);
        }

        // Save the property ID
        $this->updateSettings([
            'google_analytics_property_id' => sanitize_text_field($property_id),
        ]);

        // Clear cached data for the old property
        $this->clearGACache();

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Property saved successfully', 'uixpress'),
            'property_id' => $property_id,
        ], 200);
    }

    /**
     * Get access token using service account credentials
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

        $settings = $this->getSettings();
        $json = $settings['google_analytics_service_account'] ?? '';
        if (empty($json)) {
            return null;
        }

        $decrypted = $this->decryptToken($json);
        $credentials = json_decode($decrypted, true);
        if (!$credentials) {
            return null;
        }

        return $this->getAccessTokenFromCredentials($credentials);
    }

    /**
     * Get access token from credentials array
     * 
     * @param array $credentials Service account credentials
     * @return string|null Access token or null
     */
    private function getAccessTokenFromCredentials(array $credentials): ?string
    {
        $jwt = $this->generateJWT($credentials);
        if (!$jwt) {
            return null;
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('UiXpress GA Token Error: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token'])) {
            // Cache the token for 50 minutes (tokens last 60 minutes)
            set_transient('uixpress_ga_access_token', $body['access_token'], 3000);
            return $body['access_token'];
        }

        error_log('UiXpress GA Token Error: ' . ($body['error_description'] ?? $body['error'] ?? 'Unknown error'));
        return null;
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
        $expiry = $now + 3600;

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $payload = [
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $expiry,
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $dataToSign = $headerEncoded . '.' . $payloadEncoded;
        $privateKey = $credentials['private_key'];

        $signature = '';
        $success = openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$success) {
            error_log('UiXpress GA: Failed to sign JWT - ' . openssl_error_string());
            return null;
        }

        return $headerEncoded . '.' . $payloadEncoded . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * Base64 URL encode
     * 
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
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
     * Clear GA-related cache
     */
    private function clearGACache(): void
    {
        global $wpdb;
        
        // Delete all GA-related transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ga_%' OR option_name LIKE '_transient_timeout_ga_%' OR option_name LIKE '_transient_uixpress_ga_%' OR option_name LIKE '_transient_timeout_uixpress_ga_%'"
        );
    }
}
