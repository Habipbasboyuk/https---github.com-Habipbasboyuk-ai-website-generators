<?php
namespace AM\Support;

if (!defined('ABSPATH')) { exit; }

final class Taxonomies {
    public static function register(): void {
        register_taxonomy('agency_label', ['agency_task'], [
            'labels' => [
                'name' => __('Labels', 'agency-manager'),
                'singular_name' => __('Label', 'agency-manager'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'hierarchical' => false,
            'show_in_rest' => true,
        ]);

        register_taxonomy('agency_task_type', ['agency_task'], [
            'labels' => [
                'name' => __('Task Types', 'agency-manager'),
                'singular_name' => __('Task Type', 'agency-manager'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'hierarchical' => false,
            'show_in_rest' => true,
        ]);
    }
}
