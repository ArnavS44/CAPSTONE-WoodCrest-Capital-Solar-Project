<?php
/*
Plugin Name: WC-Utility
Version: 2.5.2
Requires PHP: 8.0
*/

if (!defined('ABSPATH')) exit;

define('WCPM_VERSION', '2.5.2');
define('WCPM_DIR', plugin_dir_path(__FILE__));
define('WCPM_URL', plugin_dir_url(__FILE__));

require_once WCPM_DIR . 'includes/shared/core/database.php';
require_once WCPM_DIR . 'includes/shared/core/schema.php';
register_activation_hook(__FILE__, 'woodcrest_pm_activate_schema');
require_once WCPM_DIR . 'includes/shared/core/frontend-helpers.php';
require_once WCPM_DIR . 'includes/shared/core/preview-context.php';
require_once WCPM_DIR . 'includes/shared/core/property-deletion.php';
require_once WCPM_DIR . 'includes/shared/core/v23-migration.php';
require_once WCPM_DIR . 'includes/shared/core/v23-tenant-business.php';
register_activation_hook(__FILE__, 'woodcrest_v24_install_access_history');
register_activation_hook(__FILE__, 'woodcrest_v24_install_suite_account_dates');
require_once WCPM_DIR . 'includes/shared/core/manager-coverage.php';
require_once WCPM_DIR . 'includes/shared/actions/frontend-management.php';
require_once WCPM_DIR . 'includes/shared/services/bulk/bulk-data.php';
add_action('admin_init', 'woodcrest_v24_ensure_upgrade_tables');

function woodcrest_v24_ensure_upgrade_tables() {
    if (!current_user_can('manage_options') || !woodcrest_v23_suite_access_ready()) return;
    global $wpdb;
    if (!woodcrest_pm_table_exists($wpdb->prefix . 'wc_import_external_ref') ||
        !woodcrest_pm_table_exists($wpdb->prefix . 'wc_import_batch')) {
        woodcrest_v24_install_schema();
    }
}

require_once WCPM_DIR . 'includes/shared/services/bulk/occupancy-chart.php';
register_activation_hook(__FILE__, 'woodcrest_v24_install_schema');
require_once WCPM_DIR . 'includes/shared/services/utilities/monthly-utilities.php';
require_once WCPM_DIR . 'includes/shared/services/invoices/invoices.php';
require_once WCPM_DIR . 'includes/shared/services/ocr/pdf-import.php';
require_once WCPM_DIR . 'includes/shared/services/ocr/posting.php';
require_once WCPM_DIR . 'includes/shared/services/triple-net/helpers.php';
require_once WCPM_DIR . 'includes/shared/services/triple-net/actions.php';
require_once WCPM_DIR . 'includes/shared/services/triple-net/shortcodes.php';

require_once WCPM_DIR . 'includes/access/shared/auth-router.php';

require_once WCPM_DIR . 'includes/access/administrator/wordpress-admin.php';
require_once WCPM_DIR . 'includes/access/administrator/frontend-management.php';
require_once WCPM_DIR . 'includes/access/administrator/demo-portal.php';

require_once WCPM_DIR . 'includes/access/property-manager/context.php';
require_once WCPM_DIR . 'includes/access/property-manager/management.php';
require_once WCPM_DIR . 'includes/access/property-manager/dashboard.php';

require_once WCPM_DIR . 'includes/access/tenant/portal.php';

add_filter('body_class', function ($classes) {
    $classes[] = 'wc-theme-' . woodcrest_fe_theme_mode();
    return $classes;
});

add_action('wp_ajax_woodcrest_save_theme_mode', function () {
    check_ajax_referer('woodcrest_theme_mode', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Sign in required.'], 401);
    }

    $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
    if (!in_array($mode, ['light', 'dark'], true)) {
        wp_send_json_error(['message' => 'Invalid theme mode.'], 400);
    }

    update_user_meta(get_current_user_id(), 'woodcrest_theme_mode', $mode);
    wp_send_json_success(['mode' => $mode]);
});


add_action('wp_enqueue_scripts', function () {
    $asset_version = static function ($relative) {
        $file = WCPM_DIR . $relative;
        return file_exists($file) ? (string) filemtime($file) : WCPM_VERSION;
    };

    wp_enqueue_style('woodcrest-theme', WCPM_URL . 'assets/css/shared/theme.css', [], $asset_version('assets/css/shared/theme.css'));
    wp_enqueue_style('woodcrest-power-style', WCPM_URL . 'assets/css/shared/portal-components.css', ['woodcrest-theme'], $asset_version('assets/css/shared/portal-components.css'));
    wp_enqueue_style('woodcrest-frontend-management', WCPM_URL . 'assets/css/shared/management-components.css', ['woodcrest-theme', 'woodcrest-power-style'], $asset_version('assets/css/shared/management-components.css'));
    wp_enqueue_style('wc-triple-net', WCPM_URL . 'assets/css/shared/triple-net-components.css', ['woodcrest-theme', 'woodcrest-frontend-management'], $asset_version('assets/css/shared/triple-net-components.css'));
    wp_enqueue_style('woodcrest-responsive', WCPM_URL . 'assets/css/shared/responsive.css', ['woodcrest-theme', 'woodcrest-power-style', 'woodcrest-frontend-management', 'wc-triple-net'], $asset_version('assets/css/shared/responsive.css'));
    wp_enqueue_style('woodcrest-admin-theme', WCPM_URL . 'assets/css/access/administrator.css', ['woodcrest-responsive'], $asset_version('assets/css/access/administrator.css'));
    wp_enqueue_style('woodcrest-manager-theme', WCPM_URL . 'assets/css/access/property-manager.css', ['woodcrest-responsive'], $asset_version('assets/css/access/property-manager.css'));
    wp_enqueue_style('woodcrest-tenant-theme', WCPM_URL . 'assets/css/access/tenant.css', ['woodcrest-responsive'], $asset_version('assets/css/access/tenant.css'));
    wp_enqueue_style('woodcrest-role-tokens', WCPM_URL . 'assets/css/shared/role-tokens.css', ['woodcrest-admin-theme', 'woodcrest-manager-theme', 'woodcrest-tenant-theme'], $asset_version('assets/css/shared/role-tokens.css'));
    wp_enqueue_style('woodcrest-theme-modes', WCPM_URL . 'assets/css/shared/theme-modes.css', ['woodcrest-role-tokens'], $asset_version('assets/css/shared/theme-modes.css'));
    wp_enqueue_script('woodcrest-frontend-management', WCPM_URL . 'assets/js/shared/frontend-management.js', [], $asset_version('assets/js/shared/frontend-management.js'), true);
    wp_localize_script('woodcrest-frontend-management', 'woodcrestFe', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'invoiceViewNonce' => wp_create_nonce('woodcrest_fe_invoice_view'),
        'unassignManagerNonce' => current_user_can('manage_options') ? wp_create_nonce('woodcrest_fe_unassign_manager_access') : '',
        'effectiveManagerId' => (current_user_can('manage_options') && (woodcrest_fe_preview_context()['role'] ?? '') === 'manager') ? woodcrest_fe_effective_manager_id() : 0,
        'themeMode' => woodcrest_fe_theme_mode(),
        'themeNonce' => wp_create_nonce('woodcrest_theme_mode'),
    ]);
    if (is_singular()) {
        global $post;
        if ($post && (has_shortcode($post->post_content, 'woodcrest_pm_import_invoice') || has_shortcode($post->post_content, 'woodcrest_manager_import_invoice'))) {
            wp_enqueue_style('woodcrest-ocr-import', WCPM_URL . 'assets/css/shared/pdf-invoice-import.css', ['woodcrest-theme-modes'], $asset_version('assets/css/shared/pdf-invoice-import.css'));
            wp_enqueue_script('woodcrest-ocr-pdfjs', WCPM_URL . 'assets/vendor/pdfjs/pdf.min.js', [], '3.11.174', true);
            wp_enqueue_script('woodcrest-ocr-tesseract', WCPM_URL . 'assets/vendor/tesseract/tesseract.min.js', [], '5.1.1', true);
            wp_enqueue_script('woodcrest-ocr-import', WCPM_URL . 'assets/js/shared/pdf-invoice-import.js', ['woodcrest-ocr-pdfjs', 'woodcrest-ocr-tesseract'], $asset_version('assets/js/shared/pdf-invoice-import.js'), true);
            wp_localize_script('woodcrest-ocr-import', 'woodcrestOcr', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('woodcrest_ocr_import'), 'pdfWorkerUrl' => WCPM_URL . 'assets/vendor/pdfjs/pdf.worker.min.js', 'ocrWorkerUrl' => WCPM_URL . 'assets/vendor/tesseract/worker.min.js', 'ocrCoreUrl' => WCPM_URL . 'assets/vendor/tesseract/core/', 'ocrLangUrl' => WCPM_URL . 'assets/vendor/tesseract/lang/']);
        }
    }
    wp_enqueue_style('woodcrest-analytics', WCPM_URL . 'assets/css/shared/analytics.css', ['woodcrest-role-tokens'], $asset_version('assets/css/shared/analytics.css'));
    wp_enqueue_style('woodcrest-standalone-graphs', WCPM_URL . 'assets/css/shared/graphs.css', ['woodcrest-analytics'], $asset_version('assets/css/shared/graphs.css'));
    wp_enqueue_style('woodcrest-property-groups', WCPM_URL . 'assets/css/shared/property-groups.css', ['woodcrest-frontend-management'], $asset_version('assets/css/shared/property-groups.css'));
    wp_enqueue_script('woodcrest-property-groups', WCPM_URL . 'assets/js/shared/property-groups.js', [], $asset_version('assets/js/shared/property-groups.js'), true);
    wp_enqueue_script('woodcrest-flash-notices', WCPM_URL . 'assets/js/shared/flash-notices.js', [], $asset_version('assets/js/shared/flash-notices.js'), true);
    wp_enqueue_script('woodcrest-standalone-graphs', WCPM_URL . 'assets/js/shared/graphs.js', [], $asset_version('assets/js/shared/graphs.js'), true);
    wp_enqueue_script('wc-triple-net', WCPM_URL . 'assets/js/shared/triple-net.js', [], $asset_version('assets/js/shared/triple-net.js'), true);
});

add_action('login_enqueue_scripts', function () {
    $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
    if (!in_array($action, ['lostpassword', 'retrievepassword', 'rp', 'resetpass', 'checkemail'], true)) return;
    $asset = 'assets/css/shared/password-reset.css';
    wp_enqueue_style('woodcrest-password-reset', WCPM_URL . $asset, [], file_exists(WCPM_DIR . $asset) ? (string)filemtime(WCPM_DIR . $asset) : WCPM_VERSION);
});
