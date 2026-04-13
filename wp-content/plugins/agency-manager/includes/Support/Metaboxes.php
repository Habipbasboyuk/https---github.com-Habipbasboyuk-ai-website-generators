<?php
namespace AM\Support;

use AM\Domain\Workflow\Workflow_Service;

if (!defined('ABSPATH')) { exit; }

final class Metaboxes {
    public static function register(): void {
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes']);
        add_action('save_post_agency_project', [self::class, 'save_project'], 10, 2);
        add_action('save_post_agency_task', [self::class, 'save_task'], 10, 2);
    }

    public static function add_meta_boxes(): void {
        add_meta_box('am_project_clients', __('Client Access', 'agency-manager'), [self::class, 'render_project_clients'], 'agency_project', 'side', 'default');
        add_meta_box('am_project_workflow', __('Workflow (per project)', 'agency-manager'), [self::class, 'render_project_workflow'], 'agency_project', 'normal', 'default');

        add_meta_box('am_task_settings', __('Task Settings', 'agency-manager'), [self::class, 'render_task_settings'], 'agency_task', 'normal', 'default');
    }

    public static function render_project_clients(\WP_Post $post): void {
        wp_nonce_field('am_save_project_' . $post->ID, 'am_nonce');
        $clients = (array) get_post_meta($post->ID, '_am_clients', true);
        $clients = array_map('intval', $clients);
        $users = get_users(['fields' => ['ID','display_name','user_email'], 'number' => 200]);
        echo '<p>' . esc_html__('Select which users (clients) can access this project in the frontend portal.', 'agency-manager') . '</p>';
        echo '<select name="am_clients[]" multiple style="width:100%;min-height:140px">';
        foreach ($users as $u) {
            $selected = in_array((int)$u->ID, $clients, true) ? 'selected' : '';
            echo '<option value="' . esc_attr((int)$u->ID) . '" ' . $selected . '>' . esc_html($u->display_name . ' (' . $u->user_email . ')') . '</option>';
        }
        echo '</select>';
        echo '<p style="opacity:.7">Tip: assign the WordPress role "Client" to these users for the intended portal experience.</p>';
    }

    public static function render_project_workflow(\WP_Post $post): void {
        wp_nonce_field('am_save_project_' . $post->ID, 'am_nonce');
        $workflow = get_post_meta($post->ID, '_am_workflow', true);
        $svc = new Workflow_Service();
        if (!is_array($workflow)) {
            $workflow = $svc->get_project_workflow($post->ID);
        }
        $json = wp_json_encode($workflow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo '<p>' . esc_html__('Project-specific statuses. Clients may only set statuses where client_can_set=true.', 'agency-manager') . '</p>';
        echo '<textarea name="am_workflow_json" rows="10" style="width:100%;font-family:monospace">' . esc_textarea($json) . '</textarea>';
        echo '<p style="opacity:.7">You can paste/edit JSON. Invalid JSON will be ignored and the previous workflow will remain.</p>';
    }

    public static function render_task_settings(\WP_Post $post): void {
        wp_nonce_field('am_save_task_' . $post->ID, 'am_nonce');

        $project_id = (int) get_post_meta($post->ID, '_am_project_id', true);
        $assignee = (int) get_post_meta($post->ID, '_am_assignee', true);
        $start = (string) get_post_meta($post->ID, '_am_start_date', true);
        $due = (string) get_post_meta($post->ID, '_am_due_date', true);
        $status = (string) get_post_meta($post->ID, '_am_status_key', true);
        $priority = (string) get_post_meta($post->ID, '_am_priority_key', true);
        $estimate = (string) get_post_meta($post->ID, '_am_estimate_hours', true);
        $client_visible = (bool) get_post_meta($post->ID, '_am_client_visible', true);

        $projects = get_posts(['post_type' => 'agency_project', 'numberposts' => 200, 'post_status' => ['publish','private','draft']]);
        $users = get_users(['fields' => ['ID','display_name'], 'number' => 200]);

        $settings = get_option('am_settings', []);
        $priorities = is_array($settings) && isset($settings['priorities']) ? (array) $settings['priorities'] : [];

        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label>' . esc_html__('Project', 'agency-manager') . '</label></th><td>';
        echo '<select name="am_project_id" style="width:100%">';
        echo '<option value="0">—</option>';
        foreach ($projects as $p) {
            $sel = selected($p->ID, $project_id, false);
            echo '<option value="' . esc_attr((int)$p->ID) . '" ' . $sel . '>' . esc_html(get_the_title($p)) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th><label>' . esc_html__('Assignee', 'agency-manager') . '</label></th><td>';
        echo '<select name="am_assignee" style="width:100%">';
        echo '<option value="0">—</option>';
        foreach ($users as $u) {
            $sel = selected($u->ID, $assignee, false);
            echo '<option value="' . esc_attr((int)$u->ID) . '" ' . $sel . '>' . esc_html($u->display_name) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th><label>' . esc_html__('Start date', 'agency-manager') . '</label></th><td>';
        echo '<input type="date" name="am_start_date" value="' . esc_attr($start) . '" />';
        echo '</td></tr>';

        echo '<tr><th><label>' . esc_html__('Due date', 'agency-manager') . '</label></th><td>';
        echo '<input type="date" name="am_due_date" value="' . esc_attr($due) . '" />';
        echo '</td></tr>';

        // Status based on project workflow.
        $statuses = [];
        if ($project_id) {
            $wf = (new Workflow_Service())->get_project_workflow($project_id);
            $statuses = (array) ($wf['statuses'] ?? []);
        }
        echo '<tr><th><label>' . esc_html__('Status', 'agency-manager') . '</label></th><td>';
        echo '<select name="am_status_key" style="width:100%">';
        echo '<option value="">—</option>';
        foreach ($statuses as $st) {
            if (!is_array($st)) continue;
            $k = (string) ($st['key'] ?? '');
            $n = (string) ($st['name'] ?? $k);
            if ($k === '') continue;
            $sel = selected($k, $status, false);
            echo '<option value="' . esc_attr($k) . '" ' . $sel . '>' . esc_html($n) . '</option>';
        }
        echo '</select>';
        echo '<p style="opacity:.7">Statuses come from the selected project workflow.</p>';
        echo '</td></tr>';

        echo '<tr><th><label>' . esc_html__('Priority', 'agency-manager') . '</label></th><td>';
        echo '<select name="am_priority_key" style="width:100%">';
        echo '<option value="">—</option>';
        foreach ($priorities as $pr) {
            if (!is_array($pr)) continue;
            $k = sanitize_key((string)($pr['key'] ?? ''));
            $n = (string)($pr['name'] ?? $k);
            if ($k === '') continue;
            $sel = selected($k, $priority, false);
            echo '<option value="' . esc_attr($k) . '" ' . $sel . '>' . esc_html($n) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th><label>' . esc_html__('Estimate (hours)', 'agency-manager') . '</label></th><td>';
        echo '<input type="number" step="0.1" min="0" name="am_estimate_hours" value="' . esc_attr($estimate) . '" />';
        echo '</td></tr>';

        echo '<tr><th><label>' . esc_html__('Client visible', 'agency-manager') . '</label></th><td>';
        echo '<label><input type="checkbox" name="am_client_visible" value="1" ' . checked(true, $client_visible, false) . ' /> ' . esc_html__('Visible in client portal', 'agency-manager') . '</label>';
        echo '</td></tr>';

        echo '</tbody></table>';
    }

    public static function save_project(int $post_id, \WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (empty($_POST['am_nonce']) || !wp_verify_nonce($_POST['am_nonce'], 'am_save_project_' . $post_id)) return;

        // Clients.
        $clients = isset($_POST['am_clients']) ? (array) $_POST['am_clients'] : [];
        $clients = array_values(array_unique(array_filter(array_map('intval', $clients))));
        update_post_meta($post_id, '_am_clients', $clients);

        // Workflow JSON.
        if (isset($_POST['am_workflow_json'])) {
            $raw = (string) $_POST['am_workflow_json'];
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && Validator::workflow_is_valid($decoded)) {
                update_post_meta($post_id, '_am_workflow', $decoded);
            }
        }
    }

    public static function save_task(int $post_id, \WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (empty($_POST['am_nonce']) || !wp_verify_nonce($_POST['am_nonce'], 'am_save_task_' . $post_id)) return;

        update_post_meta($post_id, '_am_project_id', (int) ($_POST['am_project_id'] ?? 0));
        update_post_meta($post_id, '_am_assignee', (int) ($_POST['am_assignee'] ?? 0));
        update_post_meta($post_id, '_am_start_date', sanitize_text_field((string) ($_POST['am_start_date'] ?? '')));
        update_post_meta($post_id, '_am_due_date', sanitize_text_field((string) ($_POST['am_due_date'] ?? '')));
        update_post_meta($post_id, '_am_status_key', sanitize_key((string) ($_POST['am_status_key'] ?? '')));
        update_post_meta($post_id, '_am_priority_key', sanitize_key((string) ($_POST['am_priority_key'] ?? '')));
        update_post_meta($post_id, '_am_estimate_hours', (float) ($_POST['am_estimate_hours'] ?? 0));
        update_post_meta($post_id, '_am_client_visible', !empty($_POST['am_client_visible']) ? 1 : 0);
    }
}
