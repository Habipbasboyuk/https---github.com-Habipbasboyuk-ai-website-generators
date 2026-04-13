<?php
namespace UiXpress\Rest;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class MediaReplace
 *
 * Handles media file replacement while keeping the same ID and regenerating thumbnails
 */
class MediaReplace
{
  /**
   * Constructor - registers REST API endpoints
   */
  public function __construct()
  {
    add_action("rest_api_init", ["UiXpress\Rest\MediaReplace", "register_custom_endpoints"]);
  }

  /**
   * Registers custom endpoints for media file replacement
   */
  public static function register_custom_endpoints()
  {
    // Endpoint for media file replacement
    register_rest_route('uixpress/v1', '/media/replace', [
      'methods' => 'POST',
      'callback' => ['UiXpress\Rest\MediaReplace', 'replace_media_file'],
      'permission_callback' => ['UiXpress\Rest\MediaReplace', 'check_permissions'],
      'accept_file_uploads' => true,
    ]);
  }

  /**
   * Check if the user has permission to replace media files
   *
   * @param \WP_REST_Request $request The REST request object
   * @return bool|WP_Error True if the user has permission, WP_Error object otherwise.
   */
  public static function check_permissions($request = null)
  {
    if (!$request) {
      return new \WP_Error('rest_forbidden', __('Invalid request.', 'uixpress'), ['status' => 400]);
    }
    
    return RestPermissionChecker::check_permissions($request, 'upload_files');
  }

  /**
   * Replace media file while keeping the same ID and regenerating thumbnails
   *
   * @param WP_REST_Request $request The request object
   * @return WP_REST_Response|WP_Error Response object or error
   */
  public static function replace_media_file($request)
  {
    // Include WordPress media functions
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    $media_id = $request->get_param('media_id');
    
    if (!$media_id) {
      return new \WP_Error(
        'missing_media_id',
        __('Media ID is required.', 'uixpress'),
        ['status' => 400]
      );
    }

    // Get the existing media item
    $existing_media = get_post($media_id);
    if (!$existing_media || $existing_media->post_type !== 'attachment') {
      return new \WP_Error(
        'media_not_found',
        __('Media item not found.', 'uixpress'),
        ['status' => 404]
      );
    }

    // Check if user can edit this media item
    if (!current_user_can('edit_post', $media_id)) {
      return new \WP_Error(
        'rest_forbidden',
        __('You do not have permission to edit this media item.', 'uixpress'),
        ['status' => 403]
      );
    }

    // Get the uploaded file
    $files = $request->get_file_params();
    if (empty($files['file'])) {
      return new \WP_Error(
        'no_file_uploaded',
        __('No file was uploaded.', 'uixpress'),
        ['status' => 400]
      );
    }

    $uploaded_file = $files['file'];
    
    // Validate file
    if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
      // Log detailed error server-side, but return generic message to client
      error_log('Media replace upload error: ' . $uploaded_file['error']);
      return new \WP_Error(
        'upload_error',
        __('File upload failed. Please try again.', 'uixpress'),
        ['status' => 400]
      );
    }

    // Use WordPress file validation functions for better security
    $file_type = wp_check_filetype($uploaded_file['name']);
    $file_extension = $file_type['ext'];
    
    // If no extension found, try to get it from MIME type
    if (empty($file_extension)) {
      $mime_to_ext = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'application/pdf' => 'pdf',
        // Font types
        'font/woff2' => 'woff2',
        'font/woff' => 'woff',
        'font/ttf' => 'ttf',
        'font/otf' => 'otf',
        'application/font-woff2' => 'woff2',
        'application/font-woff' => 'woff',
        'application/x-font-ttf' => 'ttf',
        'application/x-font-otf' => 'otf',
        'application/vnd.ms-fontobject' => 'eot'
      ];
      $file_extension = isset($mime_to_ext[$uploaded_file['type']]) ? $mime_to_ext[$uploaded_file['type']] : '';
    }
    
    // Validate file type using WordPress allowed types
    $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'mov', 'avi', 'mp3', 'wav', 'pdf', 'woff2', 'woff', 'ttf', 'otf', 'eot'];
    if (!$file_type['ext'] || !in_array($file_extension, $allowed_types)) {
      return new \WP_Error(
        'invalid_file_type',
        __('File type not allowed.', 'uixpress'),
        ['status' => 400]
      );
    }
    
    // Verify actual file content, not just extension/MIME type
    // Check if file exists and get real MIME type
    if (file_exists($uploaded_file['tmp_name'])) {
      $real_mime = mime_content_type($uploaded_file['tmp_name']);
      $allowed_mimes = wp_get_mime_types();
      $allowed_extensions = array_keys($allowed_mimes);
      
      // Verify the real MIME type matches expected types for the extension
      $expected_mimes = array_filter($allowed_mimes, function($mimes) use ($file_extension) {
        return in_array($file_extension, explode('|', $mimes));
      });
      
      if (!empty($expected_mimes) && !in_array($real_mime, array_keys($expected_mimes))) {
        return new \WP_Error(
          'invalid_file_content',
          __('File content does not match file type.', 'uixpress'),
          ['status' => 400]
        );
      }
    }
    
    // Check for double extension attacks (e.g., .php.jpg)
    $file_info = pathinfo($uploaded_file['name']);
    $base_filename = isset($file_info['filename']) ? $file_info['filename'] : '';
    if (preg_match('/\.(php|phtml|php3|php4|php5|phps|phar|pl|py|rb|sh|bash|exe|dll|bat|cmd|com|scr|vbs|js|jar|war|jsp|asp|aspx)$/i', $base_filename)) {
      return new \WP_Error(
        'invalid_file_name',
        __('File name contains invalid characters.', 'uixpress'),
        ['status' => 400]
      );
    }

    // Get the existing file path
    $existing_file_path = get_attached_file($media_id);
    if (!$existing_file_path || !file_exists($existing_file_path)) {
      return new \WP_Error(
        'existing_file_not_found',
        __('Original file not found.', 'uixpress'),
        ['status' => 404]
      );
    }

    // Get upload directory info
    $upload_dir = wp_upload_dir();
    $existing_file_dir = dirname($existing_file_path);
    
    // Generate new filename - sanitize to prevent path traversal
    $base_filename = isset($file_info['filename']) ? sanitize_file_name($file_info['filename']) : 'image';
    $new_filename = sanitize_file_name($base_filename . '.' . $file_extension);
    $new_file_path = $existing_file_dir . '/' . $new_filename;
    
    // Verify path is still within uploads directory to prevent path traversal
    $real_path = realpath(dirname($new_file_path));
    $upload_base_dir = realpath($upload_dir['basedir']);
    if ($real_path === false || $upload_base_dir === false || strpos($real_path, $upload_base_dir) !== 0) {
      return new \WP_Error(
        'invalid_path',
        __('Invalid file path.', 'uixpress'),
        ['status' => 400]
      );
    }

    // Handle filename conflicts
    $counter = 1;
    while (file_exists($new_file_path)) {
      $new_filename = $base_filename . '-' . $counter . '.' . $file_extension;
      $new_file_path = $existing_file_dir . '/' . $new_filename;
      $counter++;
    }

    // Move uploaded file to the correct location
    if (!move_uploaded_file($uploaded_file['tmp_name'], $new_file_path)) {
      return new \WP_Error(
        'file_move_failed',
        __('Failed to move uploaded file.', 'uixpress'),
        ['status' => 500]
      );
    }

    // Update the attachment metadata
    $attachment_data = [
      'ID' => $media_id,
      'post_mime_type' => $uploaded_file['type'],
    ];

    // Update the post
    $result = wp_update_post($attachment_data);
    if (is_wp_error($result)) {
      // Clean up the new file if post update failed
      unlink($new_file_path);
      return $result;
    }

    // Update the attachment file path in the database
    update_attached_file($media_id, $new_file_path);

    // Update attachment metadata
    $attachment_metadata = wp_generate_attachment_metadata($media_id, $new_file_path);
    wp_update_attachment_metadata($media_id, $attachment_metadata);

    // Delete old thumbnails if they exist
    $old_metadata = wp_get_attachment_metadata($media_id);
    if ($old_metadata && isset($old_metadata['sizes'])) {
      $old_file_dir = dirname($existing_file_path);
      foreach ($old_metadata['sizes'] as $size => $size_data) {
        $old_thumbnail_path = $old_file_dir . '/' . $size_data['file'];
        if (file_exists($old_thumbnail_path)) {
          unlink($old_thumbnail_path);
        }
      }
    }

    // Delete the old file
    if (file_exists($existing_file_path)) {
      unlink($existing_file_path);
    }

    // Regenerate thumbnails for images
    if (strpos($uploaded_file['type'], 'image/') === 0) {
      // Force regeneration of thumbnails
      $regenerate_metadata = wp_generate_attachment_metadata($media_id, $new_file_path);
      wp_update_attachment_metadata($media_id, $regenerate_metadata);
    }

    // Get the updated media item
    $updated_media = get_post($media_id);
    $updated_media_data = [
      'id' => $updated_media->ID,
      'title' => $updated_media->post_title,
      'source_url' => wp_get_attachment_url($media_id),
      'mime_type' => $updated_media->post_mime_type,
      'media_details' => wp_get_attachment_metadata($media_id),
    ];

    return new \WP_REST_Response([
      'success' => true,
      'data' => $updated_media_data,
      'message' => __('File replaced successfully.', 'uixpress')
    ], 200);
  }
}
