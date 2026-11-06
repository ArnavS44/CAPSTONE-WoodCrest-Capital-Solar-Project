<?php
if (!defined('ABSPATH')) exit;

function woodcrest_pm_table_exists($table) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}

function woodcrest_pm_column_exists($table, $column) {
    global $wpdb;
    if (!woodcrest_pm_table_exists($table)) return false;
    return (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
}

function woodcrest_pm_normalize_workflow_statuses() {
    global $wpdb;

    $invoice_table = $wpdb->prefix . 'wc_invoice';
    if (woodcrest_pm_column_exists($invoice_table, 'status')) {
        
        
        $wpdb->query("ALTER TABLE {$invoice_table} MODIFY status VARCHAR(20) NULL DEFAULT NULL");
        $wpdb->query("UPDATE {$invoice_table}
                      SET status = CASE UPPER(COALESCE(status, ''))
                          WHEN 'DRAFTED' THEN 'DRAFTED'
                          WHEN 'CONFIRMED' THEN 'DRAFTED'
                          WHEN 'READY' THEN 'DRAFTED'
                          WHEN 'SENT' THEN 'DRAFTED'
                          WHEN 'UNDRAFTED' THEN 'UNDRAFTED'
                          WHEN 'DRAFT' THEN 'UNDRAFTED'
                          WHEN 'VOIDED' THEN 'UNDRAFTED'
                          ELSE 'UNDRAFTED'
                      END");
        $wpdb->query("ALTER TABLE {$invoice_table} MODIFY status ENUM('DRAFTED','UNDRAFTED') NOT NULL DEFAULT 'UNDRAFTED'");
    }

    $tn_table = $wpdb->prefix . 'wc_nnn_reconciliation';
    if (woodcrest_pm_column_exists($tn_table, 'status')) {
        $wpdb->query("ALTER TABLE {$tn_table} MODIFY status VARCHAR(20) NULL DEFAULT NULL");
        $wpdb->query("UPDATE {$tn_table}
                      SET status = CASE UPPER(COALESCE(status, ''))
                          WHEN 'DRAFTED' THEN 'DRAFTED'
                          WHEN 'FINALIZED' THEN 'DRAFTED'
                          WHEN 'UNDRAFTED' THEN 'UNDRAFTED'
                          WHEN 'DRAFT' THEN 'UNDRAFTED'
                          ELSE 'UNDRAFTED'
                      END");
        $wpdb->query("ALTER TABLE {$tn_table} MODIFY status ENUM('DRAFTED','UNDRAFTED') NOT NULL DEFAULT 'UNDRAFTED'");
    }
}

function woodcrest_pm_install_schema() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'wc_utility_monthly_cost';

    $sql = "CREATE TABLE {$table} (
        monthly_cost_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        utility_account_id BIGINT(20) UNSIGNED NOT NULL,
        billing_month CHAR(7) NOT NULL,
        unit_rate DECIMAL(14,6) NOT NULL DEFAULT 0.000000,
        fixed_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        other_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        provider_bill_total DECIMAL(12,2) NULL,
        notes TEXT NULL,
        created_by_user_id BIGINT(20) UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (monthly_cost_id),
        UNIQUE KEY account_month (utility_account_id,billing_month),
        KEY utility_account_id (utility_account_id),
        KEY billing_month (billing_month)
    ) {$charset_collate};";

    dbDelta($sql);

    
    
    
    $tn_table = $wpdb->prefix . 'wc_nnn_reconciliation';
    if (woodcrest_pm_table_exists($tn_table) && !woodcrest_pm_column_exists($tn_table, 'woodcrest_responsibility_percent')) {
        $wpdb->query("ALTER TABLE {$tn_table} ADD woodcrest_responsibility_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000 AFTER total_allocable_sqft");
    }

    woodcrest_pm_normalize_workflow_statuses();
    update_option('woodcrest_pm_schema_version', '1.5.0');
}

function woodcrest_pm_activate_schema() {
    global $wpdb;
    if (!woodcrest_pm_table_exists($wpdb->prefix . 'wc_utility_monthly_cost')) woodcrest_pm_install_schema();
}

function woodcrest_pm_maybe_upgrade_schema() {
    global $wpdb;
    if (!get_option('woodcrest_pm_schema_version') && !woodcrest_pm_table_exists($wpdb->prefix . 'wc_utility_monthly_cost')) {
        woodcrest_pm_install_schema();
    }
}
add_action('plugins_loaded', 'woodcrest_pm_maybe_upgrade_schema', 5);
