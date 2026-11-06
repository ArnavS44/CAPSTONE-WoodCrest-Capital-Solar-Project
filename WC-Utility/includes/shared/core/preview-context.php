<?php
if (!defined('ABSPATH')) exit;

function woodcrest_fe_preview_session_key() {
    if (!is_user_logged_in() || !current_user_can('manage_options')) return '';
    $token = wp_get_session_token();
    if (!$token) return '';
    return 'wc_preview_' . substr(hash_hmac('sha256', get_current_user_id() . ':' . $token, wp_salt('auth')), 0, 35);
}

function woodcrest_fe_preview_context() {
    $key = woodcrest_fe_preview_session_key();
    if (!$key) return [];
    $context = get_transient($key);
    if (!is_array($context)) return [];
    $role = $context['role'] ?? '';
    $id = absint($context['user_id'] ?? 0);
    if (!$id || !get_userdata($id) || $id === get_current_user_id()) {
        delete_transient($key);
        return [];
    }
    if ($role === 'manager' && woodcrest_fe_is_property_manager($id)) return ['role'=>'manager','user_id'=>$id];
    if ($role === 'tenant' && woodcrest_fe_is_tenant_preview_candidate($id)) return ['role'=>'tenant','user_id'=>$id];
    delete_transient($key);
    return [];
}

function woodcrest_fe_is_tenant_preview_candidate($id) {
    global $wpdb;
    $id = absint($id);
    if (!$id || !get_userdata($id)) return false;
    $t = woodcrest_fe_tables();
    if ((bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$t['suite_occupancy']} WHERE tenant_user_id=%d LIMIT 1", $id))) return true;
    if (function_exists('woodcrest_v23_visible_suite_ids') && woodcrest_v23_visible_suite_ids($id)) return true;
    if (function_exists('woodcrest_v24_historical_suite_ids') && woodcrest_v24_historical_suite_ids($id)) return true;
    return false;
}

function woodcrest_fe_clear_preview() {
    $key = woodcrest_fe_preview_session_key();
    if ($key) delete_transient($key);
}

function woodcrest_fe_effective_tenant_id() {
    $context = woodcrest_fe_preview_context();
    if (($context['role'] ?? '') === 'tenant') return (int)$context['user_id'];
    return get_current_user_id();
}

add_action('template_redirect', 'woodcrest_fe_handle_preview_navigation', 0);
function woodcrest_fe_handle_preview_navigation() {
    if (!is_user_logged_in() || !current_user_can('manage_options')) return;
    $role = isset($_GET['wc_view_as']) ? sanitize_key(wp_unslash($_GET['wc_view_as'])) : '';
    if ($role !== '') {
        $id = absint($_GET['wc_view_user'] ?? 0);
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!in_array($role, ['manager','tenant'], true) || !$id || !wp_verify_nonce($nonce, 'woodcrest_view_' . $role . '_' . $id)) {
            wp_die('Invalid preview request.', 'Preview not available', ['response'=>403]);
        }
        $allowed = $role === 'manager' ? woodcrest_fe_is_property_manager($id) : woodcrest_fe_is_tenant_preview_candidate($id);
        if (!$allowed) wp_die('This user is not available for preview.', 'Preview not available', ['response'=>403]);
        set_transient(woodcrest_fe_preview_session_key(), ['role'=>$role,'user_id'=>$id], 14 * DAY_IN_SECONDS);
        wp_safe_redirect(remove_query_arg(['wc_view_as','wc_view_user','_wpnonce']));
        exit;
    }
    $is_home = is_page('admin-user-page');
    if (!$is_home && is_singular()) {
        $post = get_queried_object();
        $is_home = $post instanceof WP_Post && has_shortcode($post->post_content, 'woodcrest_admin_demo_portal');
    }
    if ($is_home) woodcrest_fe_clear_preview();
}
