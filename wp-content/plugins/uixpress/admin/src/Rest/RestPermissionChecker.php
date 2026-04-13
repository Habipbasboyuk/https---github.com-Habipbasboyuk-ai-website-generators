<?php

namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class RestPermissionChecker
 *
 * Utility class for checking REST API permissions with support for:
 * - Local requests: Nonce verification (CSRF protection)
 * - Remote requests: Basic Auth with application password validation
 *
 * @since 1.0.0
 */
class RestPermissionChecker
{
  /**
   * Checks if a request is coming from the same domain (local request)
   *
   * @param \WP_REST_Request $request The REST request object
   * @return bool True if request is from same domain, false otherwise
   */
  private static function is_local_request($request)
  {
    // Get the origin/referer from the request
    $origin = $request->get_header('Origin');
    $referer = $request->get_header('Referer');
    
    // Get current site URL
    $site_url = home_url();
    $site_host = parse_url($site_url, PHP_URL_HOST);
    
    // Check origin
    if ($origin) {
      $origin_host = parse_url($origin, PHP_URL_HOST);
      if ($origin_host && $origin_host === $site_host) {
        return true;
      }
    }
    
    // Check referer
    if ($referer) {
      $referer_host = parse_url($referer, PHP_URL_HOST);
      if ($referer_host && $referer_host === $site_host) {
        return true;
      }
    }
    
    // If no origin/referer, check if Basic Auth is present
    // If Basic Auth is present, it's likely a remote request
    $auth_header = $request->get_header('Authorization');
    if ($auth_header && strpos($auth_header, 'Basic ') === 0) {
      return false;
    }
    
    // Default to local if we can't determine (safer default)
    return true;
  }

  /**
   * Validates Basic Auth credentials (application password)
   *
   * @param \WP_REST_Request $request The REST request object
   * @return bool|WP_Error True if valid, WP_Error otherwise
   */
  private static function validate_basic_auth($request)
  {
    $auth_header = $request->get_header('Authorization');
    
    if (!$auth_header || strpos($auth_header, 'Basic ') !== 0) {
      return new \WP_Error(
        'rest_forbidden',
        __('Authentication required. Please provide Basic Auth credentials.', 'uixpress'),
        ['status' => 401]
      );
    }
    
    // Extract credentials from Basic Auth header
    $encoded_credentials = substr($auth_header, 6); // Remove "Basic " prefix
    $decoded_credentials = base64_decode($encoded_credentials, true);
    
    if ($decoded_credentials === false) {
      return new \WP_Error(
        'rest_forbidden',
        __('Invalid authentication credentials.', 'uixpress'),
        ['status' => 401]
      );
    }
    
    list($username, $password) = explode(':', $decoded_credentials, 2);
    
    if (empty($username) || empty($password)) {
      return new \WP_Error(
        'rest_forbidden',
        __('Invalid authentication credentials.', 'uixpress'),
        ['status' => 401]
      );
    }
    
    // Get user by username
    $user = get_user_by('login', $username);
    
    if (!$user) {
      return new \WP_Error(
        'rest_forbidden',
        __('Invalid authentication credentials.', 'uixpress'),
        ['status' => 401]
      );
    }
    
    // Try regular password authentication first
    $authenticated_user = wp_authenticate($username, $password);
    
    if (!is_wp_error($authenticated_user)) {
      // Regular password authentication succeeded
      wp_set_current_user($authenticated_user->ID);
      return true;
    }
    
    // If regular authentication failed, try application password (WordPress 5.6+)
    if (class_exists('WP_Application_Passwords')) {
      // Get all application passwords for this user
      $app_passwords = \WP_Application_Passwords::get_user_application_passwords($user->ID);
      
      if (!empty($app_passwords)) {
        // Check each application password
        foreach ($app_passwords as $app_password) {
          // Application passwords are stored hashed
          // The password provided should be the raw application password
          // WordPress stores them in a specific format, so we verify using wp_check_password
          if (isset($app_password['password']) && wp_check_password($password, $app_password['password'])) {
            // Application password verified successfully
            wp_set_current_user($user->ID);
            return true;
          }
        }
      }
    }
    
    // Both regular and application password authentication failed
    return new \WP_Error(
      'rest_forbidden',
      __('Invalid authentication credentials.', 'uixpress'),
      ['status' => 401]
    );
  }

  /**
   * Verifies nonce for local requests
   *
   * @param \WP_REST_Request $request The REST request object
   * @return bool|WP_Error True if valid, WP_Error otherwise
   */
  private static function verify_nonce($request)
  {
    $nonce = $request->get_header('X-WP-Nonce') ?: $request->get_param('_wpnonce');
    
    if (!$nonce) {
      return new \WP_Error(
        'rest_forbidden',
        __('Missing security token. Please refresh the page and try again.', 'uixpress'),
        ['status' => 403]
      );
    }
    
    if (!wp_verify_nonce($nonce, 'wp_rest')) {
      return new \WP_Error(
        'rest_forbidden',
        __('Invalid security token. Please refresh the page and try again.', 'uixpress'),
        ['status' => 403]
      );
    }
    
    return true;
  }

  /**
   * Main permission check method
   * 
   * Checks user capabilities and validates authentication based on request origin:
   * - Local requests: Requires nonce verification
   * - Remote requests: Requires Basic Auth with application password
   *
   * @param \WP_REST_Request $request The REST request object
   * @param string|array $required_capability Required WordPress capability(ies). Can be a single capability string or array of capabilities (all must be met)
   * @param bool $require_login Whether user must be logged in (default: true)
   * @return bool|WP_Error True if user has permission, WP_Error otherwise
   */
  public static function check_permissions($request, $required_capability = 'manage_options', $require_login = true)
  {
    // Check if user is logged in
    if ($require_login && !is_user_logged_in()) {
      return new \WP_Error(
        'rest_forbidden',
        __('You must be logged in to access this endpoint.', 'uixpress'),
        ['status' => 401]
      );
    }
    
    // Determine if this is a local or remote request
    $is_local = self::is_local_request($request);
    
    if ($is_local) {
      // Local request: verify nonce for CSRF protection
      $nonce_check = self::verify_nonce($request);
      if (is_wp_error($nonce_check)) {
        return $nonce_check;
      }
    } else {
      // Remote request: validate Basic Auth
      $auth_check = self::validate_basic_auth($request);
      if (is_wp_error($auth_check)) {
        return $auth_check;
      }
    }
    
    // Check user capabilities
    if (!empty($required_capability)) {
      if (is_array($required_capability)) {
        // Multiple capabilities: user must have all of them
        foreach ($required_capability as $cap) {
          if (!current_user_can($cap)) {
            return new \WP_Error(
              'rest_forbidden',
              sprintf(__('You do not have permission to perform this action. Required capability: %s', 'uixpress'), $cap),
              ['status' => 403]
            );
          }
        }
      } else {
        // Single capability
        if (!current_user_can($required_capability)) {
          return new \WP_Error(
            'rest_forbidden',
            sprintf(__('You do not have permission to perform this action. Required capability: %s', 'uixpress'), $required_capability),
            ['status' => 403]
          );
        }
      }
    }
    
    return true;
  }

  /**
   * Simplified permission check for endpoints that only require login
   *
   * @param \WP_REST_Request $request The REST request object
   * @return bool|WP_Error True if user is authenticated, WP_Error otherwise
   */
  public static function check_login_only($request)
  {
    return self::check_permissions($request, '', true);
  }

  /**
   * Permission check for endpoints that don't require login (public endpoints)
   * Still validates nonce/Basic Auth if provided
   *
   * @param \WP_REST_Request $request The REST request object
   * @return bool|WP_Error Always returns true (public endpoint), but validates auth if provided
   */
  public static function check_public($request)
  {
    // If user is logged in, validate their authentication
    if (is_user_logged_in()) {
      return self::check_permissions($request, '', false);
    }
    
    // Public endpoint - allow access
    return true;
  }
}

