<?php

namespace UiXpress\Rest;

/**
 * Media Analytics REST API endpoint
 * 
 * Provides media library statistics and file type breakdown
 * with efficient caching to minimize server load.
 */
class MediaAnalytics
{
    private $namespace = 'uixpress/v1';
    private $base = 'media-analytics';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_routes()
    {
        // Get analytics endpoint
        register_rest_route($this->namespace, '/' . $this->base, [
            'methods' => 'GET',
            'callback' => [$this, 'get_media_analytics'],
            'permission_callback' => [$this, 'get_media_analytics_permissions_check'],
        ]);

        // Refresh cache endpoint
        register_rest_route($this->namespace, '/' . $this->base . '/refresh', [
            'methods' => 'POST',
            'callback' => [$this, 'refresh_analytics'],
            'permission_callback' => [$this, 'get_media_analytics_permissions_check'],
        ]);
    }

    /**
     * Check if user has permission to view media analytics
     *
     * @param \WP_REST_Request $request The REST request object
     * @return bool|WP_Error True if user has permission, WP_Error otherwise
     */
    public function get_media_analytics_permissions_check($request)
    {
        return RestPermissionChecker::check_permissions($request, 'upload_files');
    }

    /**
     * Get media analytics data with caching
     */
    public function get_media_analytics($request)
    {
        // Check cache first (24 hour cache)
        $cache_key = 'uixpress_media_analytics';
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            $cached_data['from_cache'] = true;
            $cached_data['cache_expires'] = get_option('_transient_timeout_' . $cache_key);
            return rest_ensure_response($cached_data);
        }

        // Calculate analytics data
        $analytics = $this->calculate_media_analytics();
        
        // Cache for 24 hours
        set_transient($cache_key, $analytics, DAY_IN_SECONDS);
        
        $analytics['from_cache'] = false;
        return rest_ensure_response($analytics);
    }

    /**
     * Refresh analytics (clear cache and recalculate)
     */
    public function refresh_analytics($request)
    {
        delete_transient('uixpress_media_analytics');
        return $this->get_media_analytics($request);
    }

    /**
     * Calculate media analytics data
     */
    private function calculate_media_analytics()
    {
        global $wpdb;

        // Get total media count
        $total_files = (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_status = 'inherit'
        ");

        // Get file type breakdown
        $file_types = $wpdb->get_results("
            SELECT 
                SUBSTRING_INDEX(post_mime_type, '/', 1) as file_type,
                COUNT(*) as count
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_status = 'inherit'
            GROUP BY SUBSTRING_INDEX(post_mime_type, '/', 1)
            ORDER BY count DESC
        ");

        // Get recent uploads (last 30 days)
        $recent_uploads = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_status = 'inherit'
            AND post_date >= %s
        ", date('Y-m-d H:i:s', strtotime('-30 days'))));

        // Get unused media (not attached to any post)
        $unused_media = (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'attachment'
            AND post_status = 'inherit'
            AND post_parent = 0
        ");

        // Get upload directory info
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'];
        
        // Calculate total size from upload directory
        $total_size = $this->get_directory_size($upload_path);

        // Calculate large files estimate (>5MB would be ~total_size with high count)
        // For a rough estimate without checking each file
        $large_files = 0;
        if ($total_files > 0) {
            $avg_size = $total_size / $total_files;
            // Rough estimate: if average is >1MB, assume some large files
            if ($avg_size > 1048576) {
                $large_files = (int) ($total_files * 0.1); // Estimate 10%
            }
        }

        return [
            'total_files' => $total_files,
            'total_size' => $total_size,
            'total_size_formatted' => $this->format_bytes($total_size),
            'average_file_size' => $total_files > 0 ? (int) ($total_size / $total_files) : 0,
            'average_file_size_formatted' => $total_files > 0 ? $this->format_bytes($total_size / $total_files) : '0 B',
            'file_types' => $file_types,
            'recent_uploads' => $recent_uploads,
            'unused_media' => $unused_media,
            'large_files_estimate' => $large_files,
            'upload_path' => $upload_path,
            'last_updated' => current_time('mysql'),
        ];
    }

    /**
     * Get directory size recursively
     */
    private function get_directory_size($directory)
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $size = 0;
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            // Handle permission errors gracefully
            error_log('UiXpress Media Analytics: Error reading directory - ' . $e->getMessage());
        }

        return $size;
    }

    /**
     * Format bytes into human readable format
     */
    private function format_bytes($bytes, $precision = 2)
    {
        if ($bytes == 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}