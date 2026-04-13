<?php
namespace AM\Support;

if (!defined('ABSPATH')) { exit; }

final class CPT {
    public static function register(): void {
        register_post_type('agency_project', [
            'labels' => [
                'name' => __('Projects', 'agency-manager'),
                'singular_name' => __('Project', 'agency-manager'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // We add custom menu page.
            'supports' => ['title', 'editor'],
            'capability_type' => ['agency_project', 'agency_projects'],
            'map_meta_cap' => true,
        ]);

        register_post_type('agency_task', [
            'labels' => [
                'name' => __('Tasks', 'agency-manager'),
                'singular_name' => __('Task', 'agency-manager'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=agency_project',
            'supports' => ['title', 'editor', 'comments'],
            'capability_type' => ['agency_task', 'agency_tasks'],
            'map_meta_cap' => true,
        ]);

        register_post_type('agency_milestone', [
            'labels' => [
                'name' => __('Milestones', 'agency-manager'),
                'singular_name' => __('Milestone', 'agency-manager'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=agency_project',
            'supports' => ['title'],
            'capability_type' => ['agency_milestone', 'agency_milestones'],
            'map_meta_cap' => true,
        ]);
    }
}
