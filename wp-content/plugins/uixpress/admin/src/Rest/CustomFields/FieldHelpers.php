<?php
/**
 * uiXpress Custom Fields Helper Functions
 *
 * Global helper functions for retrieving and formatting custom field values.
 * These functions provide a developer-friendly API similar to ACF for accessing
 * custom field data across posts, terms, users, and comments.
 *
 * @package UiXpress
 * @since 1.3.0
 */

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Cache key for field definitions
 */
define('UIXPRESS_FIELD_CACHE_KEY', 'uixpress_field_definitions');
define('UIXPRESS_FIELD_CACHE_EXPIRY', HOUR_IN_SECONDS);

/**
 * Get a custom field value with automatic context detection
 *
 * This is the main function for retrieving custom field values. It automatically
 * detects the current context (post, term, user, comment) based on the WordPress
 * template hierarchy and global variables.
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $object_id  Optional. The object ID. Auto-detected if null.
 * @param array    $options    Optional. Array of options:
 *                             - 'format'  => 'raw'|'escaped'|'html' (default: 'escaped')
 *                             - 'default' => mixed (default value if empty)
 *                             - 'context' => 'post'|'term'|'user'|'comment' (force context)
 *                             - 'size'    => Image size for image fields
 *
 * @return mixed The field value, formatted according to options
 */
function uixpress_get_field($field_name, $object_id = null, $options = []) {
    // Validate field name
    if (empty($field_name) || !is_string($field_name)) {
        return null;
    }

    // Merge with defaults
    $options = wp_parse_args($options, [
        'format' => 'escaped',
        'default' => null,
        'context' => null,
        'size' => 'full',
    ]);

    // Detect or use provided context
    $context = $options['context'] ?: _uixpress_detect_context($object_id);
    
    // Get the object ID if not provided
    if ($object_id === null) {
        $object_id = _uixpress_get_current_object_id($context);
    }

    if (!$object_id) {
        return $options['default'];
    }

    // Get raw value based on context
    $value = _uixpress_get_meta_value($field_name, $object_id, $context);

    // Return default if empty
    if ($value === '' || $value === null || $value === false) {
        return $options['default'];
    }

    // Get field definition for type-aware formatting
    $field_def = _uixpress_get_field_definition($field_name);
    $field_type = $field_def['type'] ?? 'text';

    // Format the value based on field type and format option
    return _uixpress_format_value($value, $field_type, $options);
}

/**
 * Echo a custom field value with automatic escaping
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $object_id  Optional. The object ID. Auto-detected if null.
 * @param array    $options    Optional. Array of options (see uixpress_get_field)
 *
 * @return void
 */
function uixpress_the_field($field_name, $object_id = null, $options = []) {
    $options['format'] = $options['format'] ?? 'escaped';
    $value = uixpress_get_field($field_name, $object_id, $options);
    
    if (is_array($value) || is_object($value)) {
        // For complex types in HTML format, output appropriately
        $field_def = _uixpress_get_field_definition($field_name);
        $field_type = $field_def['type'] ?? 'text';
        
        if ($options['format'] === 'html') {
            echo _uixpress_render_html($value, $field_type, $options);
        } else {
            echo esc_html(print_r($value, true));
        }
    } else {
        echo $value;
    }
}

/**
 * Get the full field object including configuration and value
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $object_id  Optional. The object ID. Auto-detected if null.
 *
 * @return array|null Array with 'value', 'type', 'label', etc. or null if not found
 */
function uixpress_get_field_object($field_name, $object_id = null) {
    $field_def = _uixpress_get_field_definition($field_name);
    
    if (!$field_def) {
        return null;
    }

    $context = _uixpress_detect_context($object_id);
    
    if ($object_id === null) {
        $object_id = _uixpress_get_current_object_id($context);
    }

    $value = null;
    if ($object_id) {
        $value = _uixpress_get_meta_value($field_name, $object_id, $context);
    }

    return [
        'name' => $field_def['name'] ?? $field_name,
        'label' => $field_def['label'] ?? $field_name,
        'type' => $field_def['type'] ?? 'text',
        'value' => $value,
        'instructions' => $field_def['instructions'] ?? '',
        'required' => $field_def['required'] ?? false,
        'default_value' => $field_def['default_value'] ?? null,
        'placeholder' => $field_def['placeholder'] ?? '',
        'options' => $field_def['options'] ?? [],
    ];
}

/**
 * Get a post custom field value
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $post_id    Optional. The post ID. Uses current post if null.
 * @param array    $options    Optional. Array of options (see uixpress_get_field)
 *
 * @return mixed The field value
 */
function uixpress_get_post_field($field_name, $post_id = null, $options = []) {
    $options['context'] = 'post';
    return uixpress_get_field($field_name, $post_id, $options);
}

/**
 * Get a term custom field value
 *
 * @param string $field_name The field name (meta key)
 * @param int    $term_id    The term ID (required)
 * @param array  $options    Optional. Array of options (see uixpress_get_field)
 *
 * @return mixed The field value
 */
function uixpress_get_term_field($field_name, $term_id, $options = []) {
    if (!$term_id) {
        return $options['default'] ?? null;
    }
    $options['context'] = 'term';
    return uixpress_get_field($field_name, $term_id, $options);
}

/**
 * Get a user custom field value
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $user_id    Optional. The user ID. Uses current user if null.
 * @param array    $options    Optional. Array of options (see uixpress_get_field)
 *
 * @return mixed The field value
 */
function uixpress_get_user_field($field_name, $user_id = null, $options = []) {
    if ($user_id === null) {
        $user_id = get_current_user_id();
    }
    $options['context'] = 'user';
    return uixpress_get_field($field_name, $user_id, $options);
}

/**
 * Get a comment custom field value
 *
 * @param string $field_name The field name (meta key)
 * @param int    $comment_id The comment ID (required)
 * @param array  $options    Optional. Array of options (see uixpress_get_field)
 *
 * @return mixed The field value
 */
function uixpress_get_comment_field($field_name, $comment_id, $options = []) {
    if (!$comment_id) {
        return $options['default'] ?? null;
    }
    $options['context'] = 'comment';
    return uixpress_get_field($field_name, $comment_id, $options);
}

/**
 * Get an image field value with enhanced data
 *
 * Supports both single image fields and multi-image (gallery) fields.
 * When the field contains multiple images, returns an array of image objects.
 * When the field contains a single image, returns a single image object.
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $object_id  Optional. The object ID. Auto-detected if null.
 * @param array    $options    Optional. Array of options:
 *                             - 'size'    => Image size (default: 'full')
 *                             - 'context' => Force context
 *                             - 'default' => Default value if empty
 *                             - 'single'  => Force single image return (default: auto-detect)
 *
 * @return array|null Single image array, array of image arrays for multi-image, or null/default
 */
function uixpress_get_image_field($field_name, $object_id = null, $options = []) {
    $options = wp_parse_args($options, [
        'size' => 'full',
        'context' => null,
        'default' => null,
        'single' => null, // null = auto-detect, true = force single, false = force array
    ]);

    $value = uixpress_get_field($field_name, $object_id, [
        'format' => 'raw',
        'context' => $options['context'],
    ]);

    if (empty($value)) {
        return $options['default'];
    }

    // Check if this is a multi-image field (array of images)
    $is_multi = _uixpress_is_multi_image_value($value);
    
    // Determine if we should return single or multiple
    $return_single = $options['single'];
    if ($return_single === null) {
        // Auto-detect: return array if multiple images, single if one
        $return_single = !$is_multi;
    }

    if ($is_multi && !$return_single) {
        // Return array of formatted images
        return _uixpress_format_multi_image_value($value, $options['size']);
    }

    // Return single image (first one if multiple)
    return _uixpress_format_image_value($value, $options['size']);
}

/**
 * Get a file field value with enhanced data
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $object_id  Optional. The object ID. Auto-detected if null.
 * @param array    $options    Optional. Array of options
 *
 * @return array|null Array with 'id', 'url', 'title', 'filename', 'filesize', 'mime_type' or null
 */
function uixpress_get_file_field($field_name, $object_id = null, $options = []) {
    $options = wp_parse_args($options, [
        'context' => null,
        'default' => null,
    ]);

    $value = uixpress_get_field($field_name, $object_id, [
        'format' => 'raw',
        'context' => $options['context'],
    ]);

    if (empty($value)) {
        return $options['default'];
    }

    return _uixpress_format_file_value($value);
}

/**
 * Get a link field value
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $object_id  Optional. The object ID. Auto-detected if null.
 * @param array    $options    Optional. Array of options:
 *                             - 'format' => 'array'|'html' (default: 'array')
 *                             - 'class'  => CSS class for HTML output
 *
 * @return array|string|null Array with 'url', 'title', 'target' or HTML string
 */
function uixpress_get_link_field($field_name, $object_id = null, $options = []) {
    $options = wp_parse_args($options, [
        'format' => 'array',
        'class' => '',
        'context' => null,
        'default' => null,
    ]);

    $value = uixpress_get_field($field_name, $object_id, [
        'format' => 'raw',
        'context' => $options['context'],
    ]);

    if (empty($value)) {
        return $options['default'];
    }

    // Normalize link value
    $link = _uixpress_normalize_link_value($value);

    if ($options['format'] === 'html') {
        return _uixpress_render_link_html($link, $options['class']);
    }

    return $link;
}

/**
 * Get a repeater field value
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $object_id  Optional. The object ID. Auto-detected if null.
 * @param array    $options    Optional. Array of options
 *
 * @return array Array of row arrays, empty array if no rows
 */
function uixpress_get_repeater_field($field_name, $object_id = null, $options = []) {
    $options = wp_parse_args($options, [
        'context' => null,
    ]);

    $value = uixpress_get_field($field_name, $object_id, [
        'format' => 'raw',
        'context' => $options['context'],
    ]);

    if (empty($value) || !is_array($value)) {
        return [];
    }

    return $value;
}

/**
 * Get a relationship field value
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $object_id  Optional. The object ID. Auto-detected if null.
 * @param array    $options    Optional. Array of options:
 *                             - 'return' => 'objects'|'ids' (default: 'objects')
 *
 * @return array Array of WP_Post objects or IDs
 */
function uixpress_get_relationship_field($field_name, $object_id = null, $options = []) {
    $options = wp_parse_args($options, [
        'return' => 'objects',
        'context' => null,
    ]);

    $value = uixpress_get_field($field_name, $object_id, [
        'format' => 'raw',
        'context' => $options['context'],
    ]);

    if (empty($value)) {
        return [];
    }

    // Handle stored relationship data (array of objects with 'id' key)
    if (is_array($value)) {
        $ids = [];
        
        // Check if it's an array of objects or array of IDs
        if (isset($value[0]) && is_array($value[0]) && isset($value[0]['id'])) {
            // Array of relationship objects
            $ids = array_map(function($item) {
                return absint($item['id']);
            }, $value);
        } elseif (isset($value['id'])) {
            // Single relationship object
            $ids = [absint($value['id'])];
        } else {
            // Assume array of IDs
            $ids = array_map('absint', array_filter($value));
        }

        if ($options['return'] === 'ids') {
            return $ids;
        }

        // Return post objects
        $posts = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if ($post) {
                $posts[] = $post;
            }
        }
        return $posts;
    }

    // Single ID
    $id = absint($value);
    if ($options['return'] === 'ids') {
        return [$id];
    }

    $post = get_post($id);
    return $post ? [$post] : [];
}

/**
 * Get a Google Map field value
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $object_id  Optional. The object ID. Auto-detected if null.
 * @param array    $options    Optional. Array of options
 *
 * @return array|null Array with 'lat', 'lng', 'address', 'zoom' or null
 */
function uixpress_get_google_map_field($field_name, $object_id = null, $options = []) {
    $options = wp_parse_args($options, [
        'context' => null,
        'default' => null,
    ]);

    $value = uixpress_get_field($field_name, $object_id, [
        'format' => 'raw',
        'context' => $options['context'],
    ]);

    if (empty($value)) {
        return $options['default'];
    }

    // Handle JSON-encoded string values
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $value = $decoded;
        } else {
            return $options['default'];
        }
    }

    // Must be an array at this point
    if (!is_array($value)) {
        return $options['default'];
    }

    // Validate required lat/lng keys exist
    if (!isset($value['lat']) && !isset($value['lng'])) {
        return $options['default'];
    }

    return [
        'lat' => isset($value['lat']) ? floatval($value['lat']) : null,
        'lng' => isset($value['lng']) ? floatval($value['lng']) : null,
        'address' => isset($value['address']) ? sanitize_text_field($value['address']) : '',
        'zoom' => isset($value['zoom']) ? absint($value['zoom']) : 13,
    ];
}

/**
 * Get a date picker field value
 *
 * @param string   $field_name   The field name (meta key)
 * @param int|null $object_id    Optional. The object ID. Auto-detected if null.
 * @param array    $options      Optional. Array of options:
 *                               - 'format' => PHP date format (default: 'Y-m-d')
 *
 * @return string|null Formatted date string or null
 */
function uixpress_get_date_field($field_name, $object_id = null, $options = []) {
    $options = wp_parse_args($options, [
        'format' => 'Y-m-d',
        'context' => null,
        'default' => null,
    ]);

    $value = uixpress_get_field($field_name, $object_id, [
        'format' => 'raw',
        'context' => $options['context'],
    ]);

    if (empty($value)) {
        return $options['default'];
    }

    // Parse and reformat the date
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $options['default'];
    }

    return date($options['format'], $timestamp);
}

/**
 * Check if a field has a value (not empty)
 *
 * @param string   $field_name The field name (meta key)
 * @param int|null $object_id  Optional. The object ID. Auto-detected if null.
 * @param array    $options    Optional. Array of options
 *
 * @return bool True if field has a non-empty value
 */
function uixpress_have_field($field_name, $object_id = null, $options = []) {
    $value = uixpress_get_field($field_name, $object_id, array_merge($options, ['format' => 'raw']));
    
    if ($value === null || $value === '' || $value === false) {
        return false;
    }
    
    if (is_array($value) && empty($value)) {
        return false;
    }
    
    return true;
}

// ============================================================================
// OPTION PAGE HELPER FUNCTIONS
// ============================================================================

/**
 * Get a custom field value from an option page
 *
 * This function retrieves field values stored in option pages. Option page
 * values are stored in the wp_options table under 'uixpress_options_{page_slug}'.
 *
 * @param string $field_name The field name
 * @param string $page_slug  The option page slug
 * @param array  $options    Optional. Array of options:
 *                           - 'format'  => 'raw'|'escaped' (default: 'escaped')
 *                           - 'default' => mixed (default value if empty)
 *                           - 'size'    => Image size for image fields
 *
 * @return mixed The field value, formatted according to options
 *
 * @example
 * // Get a text field value
 * $company_name = uixpress_get_option_field('company_name', 'general-settings');
 *
 * // Get with default value
 * $phone = uixpress_get_option_field('phone', 'contact', ['default' => 'N/A']);
 *
 * // Get raw value
 * $data = uixpress_get_option_field('complex_field', 'settings', ['format' => 'raw']);
 */
function uixpress_get_option_field($field_name, $page_slug, $options = []) {
    // Validate inputs
    if (empty($field_name) || !is_string($field_name)) {
        return null;
    }
    
    if (empty($page_slug) || !is_string($page_slug)) {
        return null;
    }

    // Merge with defaults
    $options = wp_parse_args($options, [
        'format' => 'escaped',
        'default' => null,
        'size' => 'full',
    ]);

    // Get the option value
    $option_key = 'uixpress_options_' . sanitize_key($page_slug);
    $page_options = get_option($option_key, []);

    if (!is_array($page_options) || !isset($page_options[$field_name])) {
        return $options['default'];
    }

    $value = $page_options[$field_name];

    // Return default if empty
    if ($value === '' || $value === null || $value === false) {
        return $options['default'];
    }

    // Get field definition for type-aware formatting
    $field_def = _uixpress_get_field_definition($field_name);
    $field_type = $field_def['type'] ?? 'text';

    // Format the value based on field type and format option
    return _uixpress_format_value($value, $field_type, $options);
}

/**
 * Echo an option page field value with automatic escaping
 *
 * @param string $field_name The field name
 * @param string $page_slug  The option page slug
 * @param array  $options    Optional. Array of options (see uixpress_get_option_field)
 *
 * @return void
 */
function uixpress_the_option_field($field_name, $page_slug, $options = []) {
    $options['format'] = $options['format'] ?? 'escaped';
    $value = uixpress_get_option_field($field_name, $page_slug, $options);
    
    if (is_array($value) || is_object($value)) {
        $field_def = _uixpress_get_field_definition($field_name);
        $field_type = $field_def['type'] ?? 'text';
        
        if ($options['format'] === 'html') {
            echo _uixpress_render_html($value, $field_type, $options);
        } else {
            echo esc_html(print_r($value, true));
        }
    } else {
        echo $value;
    }
}

/**
 * Get all option values for an option page
 *
 * @param string $page_slug The option page slug
 * @param array  $options   Optional. Array of options:
 *                          - 'format' => 'raw'|'escaped' (default: 'escaped')
 *
 * @return array Associative array of field names to values
 */
function uixpress_get_option_page_values($page_slug, $options = []) {
    if (empty($page_slug) || !is_string($page_slug)) {
        return [];
    }

    $options = wp_parse_args($options, [
        'format' => 'escaped',
    ]);

    $option_key = 'uixpress_options_' . sanitize_key($page_slug);
    $page_options = get_option($option_key, []);

    if (!is_array($page_options)) {
        return [];
    }

    if ($options['format'] === 'raw') {
        return $page_options;
    }

    // Format each value
    $formatted = [];
    foreach ($page_options as $field_name => $value) {
        $field_def = _uixpress_get_field_definition($field_name);
        $field_type = $field_def['type'] ?? 'text';
        $formatted[$field_name] = _uixpress_format_value($value, $field_type, $options);
    }

    return $formatted;
}

/**
 * Check if an option page field has a value (not empty)
 *
 * @param string $field_name The field name
 * @param string $page_slug  The option page slug
 *
 * @return bool True if field has a non-empty value
 */
function uixpress_have_option_field($field_name, $page_slug) {
    $value = uixpress_get_option_field($field_name, $page_slug, ['format' => 'raw']);
    
    if ($value === null || $value === '' || $value === false) {
        return false;
    }
    
    if (is_array($value) && empty($value)) {
        return false;
    }
    
    return true;
}

/**
 * Get an image field value from an option page with enhanced data
 *
 * @param string $field_name The field name
 * @param string $page_slug  The option page slug
 * @param array  $options    Optional. Array of options:
 *                           - 'size' => Image size (default: 'full')
 *                           - 'default' => Default value if empty
 *
 * @return array|null Image data array or null
 */
function uixpress_get_option_image_field($field_name, $page_slug, $options = []) {
    $options = wp_parse_args($options, [
        'size' => 'full',
        'default' => null,
    ]);

    $value = uixpress_get_option_field($field_name, $page_slug, ['format' => 'raw']);

    if (empty($value)) {
        return $options['default'];
    }

    return _uixpress_format_image_value($value, $options['size']);
}

/**
 * Get a repeater field value from an option page
 *
 * @param string $field_name The field name
 * @param string $page_slug  The option page slug
 *
 * @return array Array of row arrays, empty array if no rows
 */
function uixpress_get_option_repeater_field($field_name, $page_slug) {
    $value = uixpress_get_option_field($field_name, $page_slug, ['format' => 'raw']);

    if (empty($value) || !is_array($value)) {
        return [];
    }

    return $value;
}

// ============================================================================
// INTERNAL HELPER FUNCTIONS
// ============================================================================

/**
 * Detect the current context based on WordPress globals
 *
 * @internal
 * @param int|null $object_id Optional object ID for context hints
 * @return string Context type: 'post', 'term', 'user', or 'comment'
 */
function _uixpress_detect_context($object_id = null) {
    // Check if we're in a term archive
    if (is_tax() || is_category() || is_tag()) {
        return 'term';
    }

    // Check if we're on an author page
    if (is_author()) {
        return 'user';
    }

    // Check if we're on a singular page or in the loop
    if (is_singular() || in_the_loop()) {
        return 'post';
    }

    // Check admin context
    if (is_admin()) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        
        if ($screen) {
            if (in_array($screen->base, ['edit-tags', 'term'])) {
                return 'term';
            }
            if (in_array($screen->base, ['user-edit', 'profile', 'user-new'])) {
                return 'user';
            }
            if ($screen->base === 'comment') {
                return 'comment';
            }
        }
    }

    // Default to post context
    return 'post';
}

/**
 * Get the current object ID based on context
 *
 * @internal
 * @param string $context The context type
 * @return int|null The object ID or null
 */
function _uixpress_get_current_object_id($context) {
    switch ($context) {
        case 'post':
            return get_the_ID() ?: null;
            
        case 'term':
            $queried = get_queried_object();
            return ($queried instanceof WP_Term) ? $queried->term_id : null;
            
        case 'user':
            if (is_author()) {
                $queried = get_queried_object();
                return ($queried instanceof WP_User) ? $queried->ID : null;
            }
            return get_current_user_id() ?: null;
            
        case 'comment':
            return null; // Comments always require explicit ID
            
        default:
            return null;
    }
}

/**
 * Get meta value based on context
 *
 * @internal
 * @param string $field_name The field name
 * @param int    $object_id  The object ID
 * @param string $context    The context type
 * @return mixed The meta value
 */
function _uixpress_get_meta_value($field_name, $object_id, $context) {
    switch ($context) {
        case 'post':
            return get_post_meta($object_id, $field_name, true);
            
        case 'term':
            return get_term_meta($object_id, $field_name, true);
            
        case 'user':
            return get_user_meta($object_id, $field_name, true);
            
        case 'comment':
            return get_comment_meta($object_id, $field_name, true);
            
        default:
            return null;
    }
}

/**
 * Get field definition from cached field groups
 *
 * @internal
 * @param string $field_name The field name to look up
 * @return array|null Field definition or null if not found
 */
function _uixpress_get_field_definition($field_name) {
    $definitions = _uixpress_get_all_field_definitions();
    
    return $definitions[$field_name] ?? null;
}

/**
 * Get all field definitions (cached)
 *
 * @internal
 * @return array Associative array of field definitions keyed by field name
 */
function _uixpress_get_all_field_definitions() {
    // Try to get from cache
    $cached = get_transient(UIXPRESS_FIELD_CACHE_KEY);
    
    if ($cached !== false) {
        return $cached;
    }

    // Load from JSON file
    $json_path = WP_CONTENT_DIR . '/uixpress-custom-fields.json';
    
    if (!file_exists($json_path)) {
        return [];
    }

    $json_content = file_get_contents($json_path);
    if ($json_content === false) {
        return [];
    }

    $field_groups = json_decode($json_content, true);
    if (!is_array($field_groups)) {
        return [];
    }

    // Build field definitions map
    $definitions = [];
    
    foreach ($field_groups as $group) {
        if (empty($group['fields']) || !is_array($group['fields'])) {
            continue;
        }

        foreach ($group['fields'] as $field) {
            $name = $field['name'] ?? '';
            if ($name) {
                $definitions[$name] = $field;
                
                // Also index sub_fields for repeaters
                if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                    foreach ($field['sub_fields'] as $sub_field) {
                        $sub_name = $sub_field['name'] ?? '';
                        if ($sub_name) {
                            $definitions[$sub_name] = $sub_field;
                        }
                    }
                }
            }
        }
    }

    // Cache the definitions
    set_transient(UIXPRESS_FIELD_CACHE_KEY, $definitions, UIXPRESS_FIELD_CACHE_EXPIRY);

    return $definitions;
}

/**
 * Clear the field definitions cache
 *
 * Call this when field groups are updated.
 *
 * @return bool True on success
 */
function uixpress_clear_field_cache() {
    return delete_transient(UIXPRESS_FIELD_CACHE_KEY);
}

/**
 * Format a value based on field type and format option
 *
 * @internal
 * @param mixed  $value      The raw value
 * @param string $field_type The field type
 * @param array  $options    Format options
 * @return mixed Formatted value
 */
function _uixpress_format_value($value, $field_type, $options) {
    $format = $options['format'] ?? 'escaped';
    
    // Raw format - return as-is
    if ($format === 'raw') {
        return $value;
    }

    // Type-specific formatting
    switch ($field_type) {
        case 'text':
        case 'password':
            return ($format === 'escaped') ? esc_html($value) : $value;
            
        case 'email':
            return ($format === 'escaped') ? sanitize_email($value) : $value;
            
        case 'url':
            return ($format === 'escaped') ? esc_url($value) : $value;
            
        case 'textarea':
            if ($format === 'html') {
                return nl2br(esc_html($value));
            }
            return ($format === 'escaped') ? esc_html($value) : $value;
            
        case 'wysiwyg':
            if ($format === 'html' || $format === 'escaped') {
                return wp_kses_post($value);
            }
            return $value;
            
        case 'number':
        case 'range':
            return is_numeric($value) ? floatval($value) : 0;
            
        case 'true_false':
            return (bool) $value;
            
        case 'color_picker':
            // Validate hex color
            if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $value)) {
                return $value;
            }
            return ($format === 'escaped') ? esc_attr($value) : $value;
            
        case 'date_picker':
        case 'time_picker':
        case 'date_time_picker':
            return ($format === 'escaped') ? esc_html($value) : $value;
            
        case 'select':
        case 'radio':
        case 'button_group':
            if (is_array($value)) {
                return ($format === 'escaped') 
                    ? array_map('esc_html', $value) 
                    : $value;
            }
            return ($format === 'escaped') ? esc_html($value) : $value;
            
        case 'checkbox':
            if (is_array($value)) {
                return ($format === 'escaped') 
                    ? array_map('esc_html', $value) 
                    : $value;
            }
            return (bool) $value;
            
        case 'image':
        case 'file':
        case 'gallery':
            // Complex types - return structured data
            return $value;
            
        case 'link':
            return _uixpress_normalize_link_value($value);
            
        case 'relationship':
        case 'post_object':
        case 'page_link':
        case 'taxonomy':
        case 'user':
            // Complex types - return as-is
            return $value;
            
        case 'google_map':
            return $value;
            
        case 'oembed':
            if ($format === 'html' && is_string($value)) {
                // Get the embed HTML
                $embed = wp_oembed_get($value);
                return $embed ?: esc_url($value);
            }
            return ($format === 'escaped') ? esc_url($value) : $value;
            
        case 'repeater':
        case 'group':
            // Complex types - return as-is
            return $value;
            
        default:
            // Unknown type - escape as text
            if (is_string($value)) {
                return ($format === 'escaped') ? esc_html($value) : $value;
            }
            return $value;
    }
}

/**
 * Format an image field value with additional data
 *
 * @internal
 * @param mixed  $value The raw image value
 * @param string $size  The image size
 * @return array|null Formatted image data
 */
function _uixpress_format_image_value($value, $size = 'full') {
    $attachment_id = null;
    $stored_data = [];

    // Handle different value formats
    if (is_array($value)) {
        if (isset($value['id'])) {
            $attachment_id = absint($value['id']);
            $stored_data = $value;
        } elseif (isset($value[0]) && is_array($value[0]) && isset($value[0]['id'])) {
            // Array of images - return first one
            $attachment_id = absint($value[0]['id']);
            $stored_data = $value[0];
        }
    } elseif (is_numeric($value)) {
        $attachment_id = absint($value);
    }

    if (!$attachment_id) {
        return null;
    }

    // Get the image src for the requested size
    $image_src = wp_get_attachment_image_src($attachment_id, $size);
    
    if (!$image_src) {
        return null;
    }

    // Get alt text
    $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
    if (empty($alt) && isset($stored_data['alt_text'])) {
        $alt = $stored_data['alt_text'];
    }

    // Get attachment post for title
    $attachment = get_post($attachment_id);
    $title = $attachment ? $attachment->post_title : '';
    if (empty($title) && isset($stored_data['title'])) {
        $title = $stored_data['title'];
    }

    // Build sizes array
    $sizes = [];
    $registered_sizes = get_intermediate_image_sizes();
    $registered_sizes[] = 'full';
    
    foreach ($registered_sizes as $size_name) {
        $src = wp_get_attachment_image_src($attachment_id, $size_name);
        if ($src) {
            $sizes[$size_name] = [
                'url' => $src[0],
                'width' => $src[1],
                'height' => $src[2],
            ];
        }
    }

    return [
        'id' => $attachment_id,
        'url' => $image_src[0],
        'width' => $image_src[1],
        'height' => $image_src[2],
        'alt' => esc_attr($alt),
        'title' => esc_attr($title),
        'caption' => $attachment ? esc_html($attachment->post_excerpt) : '',
        'description' => $attachment ? esc_html($attachment->post_content) : '',
        'mime_type' => $stored_data['mime_type'] ?? ($attachment ? $attachment->post_mime_type : ''),
        'sizes' => $sizes,
    ];
}

/**
 * Check if a value represents multiple images
 *
 * @internal
 * @param mixed $value The raw image value
 * @return bool True if the value contains multiple images
 */
function _uixpress_is_multi_image_value($value) {
    if (!is_array($value)) {
        return false;
    }

    // Check if it's an indexed array of images (not an associative array with 'id')
    if (isset($value['id'])) {
        return false; // Single image object
    }

    // Check if first element is an image object or ID
    if (isset($value[0])) {
        // Array of image objects
        if (is_array($value[0]) && isset($value[0]['id'])) {
            return true;
        }
        // Array of attachment IDs
        if (is_numeric($value[0])) {
            return count($value) > 1 || !isset($value['id']);
        }
    }

    return false;
}

/**
 * Format multiple images with enhanced data
 *
 * @internal
 * @param array  $value The raw multi-image value
 * @param string $size  The image size
 * @return array Array of formatted image data
 */
function _uixpress_format_multi_image_value($value, $size = 'full') {
    $images = [];

    if (!is_array($value)) {
        return $images;
    }

    foreach ($value as $item) {
        $formatted = _uixpress_format_single_image($item, $size);
        if ($formatted) {
            $images[] = $formatted;
        }
    }

    return $images;
}

/**
 * Format a single image item (handles both object and ID formats)
 *
 * @internal
 * @param mixed  $item The image item (array with 'id' or numeric ID)
 * @param string $size The image size
 * @return array|null Formatted image data
 */
function _uixpress_format_single_image($item, $size = 'full') {
    $attachment_id = null;
    $stored_data = [];

    if (is_array($item) && isset($item['id'])) {
        $attachment_id = absint($item['id']);
        $stored_data = $item;
    } elseif (is_numeric($item)) {
        $attachment_id = absint($item);
    }

    if (!$attachment_id) {
        return null;
    }

    // Get the image src for the requested size
    $image_src = wp_get_attachment_image_src($attachment_id, $size);
    
    if (!$image_src) {
        return null;
    }

    // Get alt text
    $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
    if (empty($alt) && isset($stored_data['alt_text'])) {
        $alt = $stored_data['alt_text'];
    }

    // Get attachment post for title
    $attachment = get_post($attachment_id);
    $title = $attachment ? $attachment->post_title : '';
    if (empty($title) && isset($stored_data['title'])) {
        $title = $stored_data['title'];
    }

    // Build sizes array
    $sizes = [];
    $registered_sizes = get_intermediate_image_sizes();
    $registered_sizes[] = 'full';
    
    foreach ($registered_sizes as $size_name) {
        $src = wp_get_attachment_image_src($attachment_id, $size_name);
        if ($src) {
            $sizes[$size_name] = [
                'url' => $src[0],
                'width' => $src[1],
                'height' => $src[2],
            ];
        }
    }

    return [
        'id' => $attachment_id,
        'url' => $image_src[0],
        'width' => $image_src[1],
        'height' => $image_src[2],
        'alt' => esc_attr($alt),
        'title' => esc_attr($title),
        'caption' => $attachment ? esc_html($attachment->post_excerpt) : '',
        'description' => $attachment ? esc_html($attachment->post_content) : '',
        'mime_type' => $stored_data['mime_type'] ?? ($attachment ? $attachment->post_mime_type : ''),
        'sizes' => $sizes,
    ];
}

/**
 * Format a file field value with additional data
 *
 * @internal
 * @param mixed $value The raw file value
 * @return array|null Formatted file data
 */
function _uixpress_format_file_value($value) {
    $attachment_id = null;
    $stored_data = [];

    // Handle different value formats
    if (is_array($value)) {
        if (isset($value['id'])) {
            $attachment_id = absint($value['id']);
            $stored_data = $value;
        } elseif (isset($value[0]) && is_array($value[0]) && isset($value[0]['id'])) {
            // Array of files - return first one
            $attachment_id = absint($value[0]['id']);
            $stored_data = $value[0];
        }
    } elseif (is_numeric($value)) {
        $attachment_id = absint($value);
    }

    if (!$attachment_id) {
        return null;
    }

    $url = wp_get_attachment_url($attachment_id);
    if (!$url) {
        return null;
    }

    $attachment = get_post($attachment_id);
    $file_path = get_attached_file($attachment_id);
    
    return [
        'id' => $attachment_id,
        'url' => esc_url($url),
        'title' => $attachment ? esc_attr($attachment->post_title) : '',
        'filename' => $file_path ? basename($file_path) : '',
        'filesize' => $file_path && file_exists($file_path) ? filesize($file_path) : 0,
        'filesize_formatted' => $file_path && file_exists($file_path) ? size_format(filesize($file_path)) : '',
        'mime_type' => $stored_data['mime_type'] ?? ($attachment ? $attachment->post_mime_type : ''),
        'icon' => wp_mime_type_icon($attachment_id),
    ];
}

/**
 * Normalize a link field value to consistent format
 *
 * @internal
 * @param mixed $value The raw link value
 * @return array|null Normalized link data
 */
function _uixpress_normalize_link_value($value) {
    if (empty($value)) {
        return null;
    }

    // Handle string URL
    if (is_string($value)) {
        return [
            'url' => esc_url($value),
            'title' => '',
            'target' => '_self',
        ];
    }

    // Handle array format
    if (is_array($value)) {
        return [
            'url' => isset($value['url']) ? esc_url($value['url']) : '',
            'title' => isset($value['title']) ? esc_attr($value['title']) : '',
            'target' => isset($value['target']) ? esc_attr($value['target']) : '_self',
        ];
    }

    return null;
}

/**
 * Render a link as HTML
 *
 * @internal
 * @param array  $link  The link array
 * @param string $class Optional CSS class
 * @return string HTML anchor tag
 */
function _uixpress_render_link_html($link, $class = '') {
    if (empty($link) || empty($link['url'])) {
        return '';
    }

    $attrs = [
        'href' => esc_url($link['url']),
    ];

    if (!empty($link['target']) && $link['target'] !== '_self') {
        $attrs['target'] = esc_attr($link['target']);
        if ($link['target'] === '_blank') {
            $attrs['rel'] = 'noopener noreferrer';
        }
    }

    if (!empty($class)) {
        $attrs['class'] = esc_attr($class);
    }

    $attr_string = '';
    foreach ($attrs as $name => $value) {
        $attr_string .= sprintf(' %s="%s"', $name, $value);
    }

    $title = !empty($link['title']) ? esc_html($link['title']) : esc_url($link['url']);

    return sprintf('<a%s>%s</a>', $attr_string, $title);
}

/**
 * Render a value as HTML based on field type
 *
 * @internal
 * @param mixed  $value      The value
 * @param string $field_type The field type
 * @param array  $options    Render options
 * @return string HTML output
 */
function _uixpress_render_html($value, $field_type, $options = []) {
    switch ($field_type) {
        case 'link':
            return _uixpress_render_link_html($value, $options['class'] ?? '');
            
        case 'image':
            if (is_array($value) && isset($value['url'])) {
                return sprintf(
                    '<img src="%s" alt="%s" width="%s" height="%s">',
                    esc_url($value['url']),
                    esc_attr($value['alt'] ?? ''),
                    esc_attr($value['width'] ?? ''),
                    esc_attr($value['height'] ?? '')
                );
            }
            return '';
            
        case 'oembed':
            if (is_string($value)) {
                $embed = wp_oembed_get($value);
                return $embed ?: '';
            }
            return '';
            
        case 'google_map':
            if (is_array($value) && isset($value['lat']) && isset($value['lng'])) {
                return sprintf(
                    '<div class="uixpress-map" data-lat="%s" data-lng="%s" data-zoom="%s" data-address="%s"></div>',
                    esc_attr($value['lat']),
                    esc_attr($value['lng']),
                    esc_attr($value['zoom'] ?? 13),
                    esc_attr($value['address'] ?? '')
                );
            }
            return '';
            
        default:
            if (is_string($value)) {
                return esc_html($value);
            }
            return '';
    }
}

/**
 * Hook to clear cache when field groups are saved
 */
add_action('uixpress_field_groups_saved', 'uixpress_clear_field_cache');
