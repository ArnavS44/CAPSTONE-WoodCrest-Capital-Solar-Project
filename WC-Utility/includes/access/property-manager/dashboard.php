<?php
function woodcrest_get_manager_id() {
    $effective = woodcrest_fe_effective_manager_id();
    return $effective ?: get_current_user_id();
}

function woodcrest_get_manager($manager_id) {
    global $woodcrest_db;
    return $woodcrest_db->get_row($woodcrest_db->prepare("
        SELECT ID AS user_id, display_name AS full_name, user_email AS email
        FROM {$woodcrest_db->app_user}
        WHERE ID = %d
    ", $manager_id));
}

function woodcrest_get_manager_summary($manager_id) {
    global $woodcrest_db;
    $today = current_time('Y-m-d');
    return $woodcrest_db->get_row($woodcrest_db->prepare("
        SELECT
            COUNT(DISTINCT upa.property_id) AS total_properties,
            COUNT(DISTINCT CASE WHEN so.end_date IS NULL OR so.end_date >= CURDATE() THEN so.tenant_user_id END) AS total_active_tenants,
            COUNT(DISTINCT CASE WHEN m.utility_type = 'ELECTRIC' THEN m.meter_id END) AS electric_meters,
            COUNT(DISTINCT CASE WHEN m.utility_type = 'WATER' THEN m.meter_id END) AS water_meters
        FROM {$woodcrest_db->user_property_access} upa
        LEFT JOIN {$woodcrest_db->property} p ON upa.property_id = p.property_id
        LEFT JOIN {$woodcrest_db->suite} s ON s.property_id = p.property_id
        LEFT JOIN {$woodcrest_db->suite_occupancy} so ON so.suite_id = s.suite_id
        LEFT JOIN {$woodcrest_db->meter} m ON m.property_id = p.property_id
        WHERE upa.user_id = %d AND upa.access_scope = 'PROPERTY_MANAGER'
          AND (upa.start_date IS NULL OR upa.start_date <= %s)
          AND (upa.end_date IS NULL OR upa.end_date > %s)
    ", $manager_id, $today, $today));
}

function woodcrest_get_manager_billing_totals($manager_id) {
    global $woodcrest_db;
    $today = current_time('Y-m-d');
    $rows = $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT period_end, SUM(total_amount) AS total_billed
        FROM {$woodcrest_db->invoice} i
        JOIN {$woodcrest_db->user_property_access} upa ON i.property_id = upa.property_id
        WHERE upa.user_id = %d AND upa.access_scope = 'PROPERTY_MANAGER'
          AND (upa.start_date IS NULL OR upa.start_date <= %s)
          AND (upa.end_date IS NULL OR upa.end_date > %s)
        GROUP BY period_end ORDER BY period_end DESC LIMIT 2
    ", $manager_id, $today, $today));
    $curr = isset($rows[0]) ? (float)$rows[0]->total_billed : 0;
    $prev = isset($rows[1]) ? (float)$rows[1]->total_billed : 0;
    return (object)['current_total' => $curr, 'previous_total' => $prev, 'diff_total' => $curr - $prev];
}

function woodcrest_get_manager_tenant_rows($manager_id) {
    global $woodcrest_db;
    $today = current_time('Y-m-d');
    return $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT u.ID AS tenant_user_id, u.display_name AS tenant_name, p.property_name, p.city, p.state, s.suite_label,
               (SELECT total_amount FROM {$woodcrest_db->invoice} WHERE tenant_user_id = u.ID AND property_id = p.property_id ORDER BY period_end DESC LIMIT 1) AS current_month_cost,
               (SELECT total_amount FROM {$woodcrest_db->invoice} WHERE tenant_user_id = u.ID AND property_id = p.property_id ORDER BY period_end DESC LIMIT 1 OFFSET 1) AS previous_month_cost,
               (SELECT status FROM {$woodcrest_db->invoice} WHERE tenant_user_id = u.ID AND property_id = p.property_id ORDER BY period_end DESC LIMIT 1) AS invoice_status,
               (SELECT meter_number FROM {$woodcrest_db->meter} m JOIN {$woodcrest_db->meter_responsibility} mr ON m.meter_id = mr.meter_id WHERE mr.tenant_user_id = u.ID AND m.property_id = p.property_id AND m.utility_type = 'ELECTRIC' LIMIT 1) AS electric_meter_number,
               (SELECT usage_amount FROM {$woodcrest_db->utility_reading} ur JOIN {$woodcrest_db->meter} m ON ur.meter_id = m.meter_id JOIN {$woodcrest_db->meter_responsibility} mr ON m.meter_id = mr.meter_id WHERE mr.tenant_user_id = u.ID AND m.property_id = p.property_id AND m.utility_type = 'ELECTRIC' ORDER BY reading_date DESC LIMIT 1) AS current_kwh,
               (SELECT meter_number FROM {$woodcrest_db->meter} m JOIN {$woodcrest_db->meter_responsibility} mr ON m.meter_id = mr.meter_id WHERE mr.tenant_user_id = u.ID AND m.property_id = p.property_id AND m.utility_type = 'WATER' LIMIT 1) AS water_meter_number,
               (SELECT usage_amount FROM {$woodcrest_db->utility_reading} ur JOIN {$woodcrest_db->meter} m ON ur.meter_id = m.meter_id JOIN {$woodcrest_db->meter_responsibility} mr ON m.meter_id = mr.meter_id WHERE mr.tenant_user_id = u.ID AND m.property_id = p.property_id AND m.utility_type = 'WATER' ORDER BY reading_date DESC LIMIT 1) AS current_water_usage
        FROM {$woodcrest_db->user_property_access} upa
        JOIN {$woodcrest_db->property} p ON upa.property_id = p.property_id
        JOIN {$woodcrest_db->suite} s ON s.property_id = p.property_id
        JOIN {$woodcrest_db->suite_occupancy} so ON so.suite_id = s.suite_id AND (so.end_date IS NULL OR so.end_date >= CURDATE())
        JOIN {$woodcrest_db->app_user} u ON u.ID = so.tenant_user_id
        WHERE upa.user_id = %d AND upa.access_scope = 'PROPERTY_MANAGER'
          AND (upa.start_date IS NULL OR upa.start_date <= %s)
          AND (upa.end_date IS NULL OR upa.end_date > %s)
        ORDER BY p.property_name, s.suite_label
    ", $manager_id, $today, $today));
}




function woodcrest_manager_header_html() {
    $manager_id = woodcrest_get_manager_id();
    $manager = woodcrest_get_manager($manager_id);
    if (!$manager) return '<p>Manager not found.</p>';
    ob_start(); ?>
    <div class="woodcrest-header-box wc-role-manager">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="woodcrest-header-badge manager">Property Manager</div>
                <h1><?php echo esc_html($manager->full_name); ?></h1>
                <p><?php echo esc_html($manager->email); ?></p>
            </div>
            <?php echo woodcrest_fe_header_nav_html('manager'); ?>
        </div>
    </div>
    <?php return ob_get_clean();
}


function woodcrest_manager_banner_html() {
    return '<div class="woodcrest-manager-banner"><div class="woodcrest-manager-banner-title">DASHBOARD / UTILITY SUMMARY</div></div>';
}


function woodcrest_v251_card_icon($key) {
    $shapes = [
        'tenants' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'triple' => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M9 6h6M9 10h1M14 10h1M9 14h1M14 14h1M9 18h1M14 18h1"/>',
        'invoices' => '<path d="M4 2h16v20l-4-2-4 2-4-2-4 2V2Z"/><path d="M8 7h8M8 11h8M8 15h5"/>',
        'usage' => '<path d="M3 3v18h18"/><path d="m7 16 4-5 4 3 5-8"/><path d="M16 6h4v4"/>',
        'setup' => '<path d="M12 2v3m0 14v3M4.93 4.93l2.12 2.12m9.9 9.9 2.12 2.12M2 12h3m14 0h3M4.93 19.07l2.12-2.12M16.95 7.05l2.12-2.12"/><circle cx="12" cy="12" r="4"/>',
        'properties' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 21V9h6v12M7 7h.01M17 7h.01"/>',
    ];
    if (!isset($shapes[$key])) return '';
    return '<svg class="wc-v251-card-icon" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $shapes[$key] . '</svg>';
}
function woodcrest_v251_dashboard_card($key, $label, $subtitle, $number = null) {
    $html = '<div class="woodcrest-manager-action-card wc-v251-dashboard-card">';
    $html .= woodcrest_v251_card_icon($key);
    if ($number !== null) $html .= '<span class="woodcrest-manager-action-number">' . (int)$number . '</span>';
    $html .= '<span class="woodcrest-manager-action-label">' . esc_html($label) . '</span>';
    $html .= '<span class="wc-v251-card-caption">' . esc_html($subtitle) . '</span></div>';
    return $html;
}
function woodcrest_card_active_accounts_html() {
    $summary = woodcrest_get_manager_summary(woodcrest_get_manager_id());
    return woodcrest_v251_dashboard_card('tenants', 'Tenants', 'Accounts & occupancy', $summary->total_active_tenants ?? 0);
}
function woodcrest_card_unpaid_bills_html() {
    return woodcrest_v251_dashboard_card('triple', 'Triple Net', 'Annual expenses');
}
function woodcrest_card_invoices_html() {
    return woodcrest_v251_dashboard_card('invoices', 'Invoices', 'Tenant billing');
}
function woodcrest_card_usage_html() {
    return woodcrest_v251_dashboard_card('usage', 'Manage Utilities', 'Monthly operations');
}
function woodcrest_card_billing_html() {
    return woodcrest_v251_dashboard_card('setup', 'Utility Setup', 'Accounts & meters');
}
function woodcrest_card_properties_html() {
    $summary = woodcrest_get_manager_summary(woodcrest_get_manager_id());
    return woodcrest_v251_dashboard_card('properties', 'Properties & Suites', 'Property details', $summary->total_properties ?? 0);
}

add_shortcode('woodcrest_card_active_accounts', 'woodcrest_card_active_accounts_html');
add_shortcode('woodcrest_card_unpaid_bills', 'woodcrest_card_unpaid_bills_html');
add_shortcode('woodcrest_card_invoices', 'woodcrest_card_invoices_html');
add_shortcode('woodcrest_card_usage', 'woodcrest_card_usage_html');
add_shortcode('woodcrest_card_billing', 'woodcrest_card_billing_html');
add_shortcode('woodcrest_card_properties', 'woodcrest_card_properties_html');


function woodcrest_manager_action_grid_html() {
    ob_start(); ?>
    <div class="woodcrest-manager-action-grid">
        <?php echo woodcrest_card_active_accounts_html(); ?>
        <?php echo woodcrest_card_unpaid_bills_html(); ?>
        <?php echo woodcrest_card_invoices_html(); ?>
        <?php echo woodcrest_card_usage_html(); ?>
        <?php echo woodcrest_card_billing_html(); ?>
        <?php echo woodcrest_card_properties_html(); ?>
    </div>
    <?php return ob_get_clean();
}


function woodcrest_manager_tenant_table_html() {
    $id = woodcrest_get_manager_id();
    $rows = woodcrest_get_manager_tenant_rows($id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-manager-panel wc-role-manager-content woodcrest-card-full wc-standalone-table-tools">
        <div class="woodcrest-card-header"><h2>Tenant Management Table</h2></div>
        <div class="woodcrest-table-wrap"><table class="woodcrest-table woodcrest-fe-table" id="woodcrest-manager-tenant-table">
            <thead><tr><th>Tenant</th><th>Property</th><th>Suite</th><th>City</th><th>State</th><th>Current Cost</th><th>Previous Cost</th><th>Monthly Diff</th></tr></thead>
            <tbody><?php if ($rows): foreach ($rows as $r): 
                $diff = ($r->current_month_cost !== null && $r->previous_month_cost !== null) ? (float)$r->current_month_cost - (float)$r->previous_month_cost : null; ?>
                <tr class="woodcrest-search-row">
                    <td><strong><?php echo esc_html($r->tenant_name); ?></strong></td>
                    <td><?php echo esc_html($r->property_name); ?></td>
                    <td><?php echo esc_html($r->suite_label); ?></td>
                    <td><?php echo esc_html($r->city); ?></td>
                    <td><?php echo esc_html($r->state); ?></td>
                    <td><?php echo number_format((float)$r->current_month_cost, 2); ?> $</td>
                    <td><?php echo number_format((float)$r->previous_month_cost, 2); ?> $</td>
                    <td class="<?php echo woodcrest_change_class($diff); ?>"><?php echo woodcrest_format_money_change($diff); ?></td>
                </tr>
            <?php endforeach; else: ?><tr><td colspan="8">No records found.</td></tr><?php endif; ?></tbody>
        </table></div>
    </section>
    <?php return ob_get_clean();
}


function woodcrest_manager_usage_summary_table_html() {
    $id = woodcrest_get_manager_id();
    $rows = woodcrest_get_manager_tenant_rows($id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-manager-panel wc-role-manager-content"><div class="woodcrest-card-header"><h2>Usage Tracking Table</h2></div><div class="woodcrest-table-wrap"><table class="woodcrest-table">
        <thead><tr><th>Tenant</th><th>Property</th><th>Electric Meter</th><th>kWh</th><th>Water Meter</th><th>Latest Usage</th></tr></thead>
        <tbody><?php if ($rows): foreach ($rows as $r): ?>
            <tr>
                <td><strong><?php echo esc_html($r->tenant_name); ?></strong></td>
                <td><?php echo esc_html($r->property_name); ?></td>
                <td><?php echo esc_html($r->electric_meter_number ?: '—'); ?></td>
                <td><?php echo $r->current_kwh ? number_format($r->current_kwh, 2) : '—'; ?></td>
                <td><?php echo esc_html($r->water_meter_number ?: '—'); ?></td>
                <td><?php echo $r->current_water_usage ? number_format($r->current_water_usage, 2) : '—'; ?></td>
            </tr>
        <?php endforeach; else: ?><tr><td colspan="6">No usage data.</td></tr><?php endif; ?></tbody>
    </table></div></section>
    <?php return ob_get_clean();
}


function woodcrest_manager_billing_table_html() {
    $id = woodcrest_get_manager_id();
    $rows = woodcrest_get_manager_tenant_rows($id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-manager-panel wc-role-manager-content"><div class="woodcrest-card-header"><h2>Billing Management Table</h2></div><div class="woodcrest-table-wrap"><table class="woodcrest-table">
        <thead><tr><th>Tenant</th><th>Property</th><th>Current Cost</th><th>Previous Cost</th><th>Status</th></tr></thead>
        <tbody><?php if ($rows): foreach ($rows as $r): ?>
            <tr>
                <td><strong><?php echo esc_html($r->tenant_name); ?></strong></td>
                <td><?php echo esc_html($r->property_name); ?></td>
                <td><?php echo number_format((float)$r->current_month_cost, 2); ?> $</td>
                <td><?php echo number_format((float)$r->previous_month_cost, 2); ?> $</td>
                <td><span class="woodcrest-badge status-<?php echo strtolower(woodcrest_fe_invoice_status_label($r->invoice_status)); ?>"><?php echo esc_html(woodcrest_fe_invoice_status_label($r->invoice_status)); ?></span></td>
            </tr>
        <?php endforeach; else: ?><tr><td colspan="5">No billing records.</td></tr><?php endif; ?></tbody>
    </table></div></section>
    <?php return ob_get_clean();
}


function woodcrest_manager_summary_stats_html() {
    $id = woodcrest_get_manager_id();
    $manager = woodcrest_get_manager($id);
    $s = woodcrest_get_manager_summary($id);
    if (!$s) $s = (object)['total_properties'=>0,'electric_meters'=>0,'water_meters'=>0];
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-manager-panel wc-role-manager-content"><div class="woodcrest-card-header"><h2>Manage Dashboard</h2></div><div class="woodcrest-manager-manage-grid">
        <div class="woodcrest-mini-stat"><span>Manager</span><strong><?php echo esc_html($manager->full_name); ?></strong></div>
        <div class="woodcrest-mini-stat"><span>Managed Properties</span><strong><?php echo $s->total_properties; ?></strong></div>
        <div class="woodcrest-mini-stat"><span>Electric Meters</span><strong><?php echo $s->electric_meters; ?></strong></div>
        <div class="woodcrest-mini-stat"><span>Water Meters</span><strong><?php echo $s->water_meters; ?></strong></div>
    </div></section>
    <?php return ob_get_clean();
}


function woodcrest_manager_unpaid_bills_html() {
    $id = woodcrest_get_manager_id();
    $rows = woodcrest_get_manager_tenant_rows($id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-manager-panel wc-role-manager-content"><div class="woodcrest-card-header"><h2>Undrafted Invoices</h2></div><div class="woodcrest-table-wrap"><table class="woodcrest-table">
        <thead><tr><th>Tenant</th><th>Property</th><th>Cost</th><th>Status</th></tr></thead>
        <tbody><?php 
        $has_unpaid = false;
        if ($rows): foreach ($rows as $r): 
            if (woodcrest_fe_invoice_status_label($r->invoice_status) === 'UNDRAFTED'): $has_unpaid = true; ?>
                <tr><td><strong><?php echo esc_html($r->tenant_name); ?></strong></td><td><?php echo esc_html($r->property_name); ?></td><td><?php echo number_format((float)$r->current_month_cost, 2); ?> $</td><td><span class="woodcrest-badge status-<?php echo strtolower(woodcrest_fe_invoice_status_label($r->invoice_status)); ?>"><?php echo esc_html(woodcrest_fe_invoice_status_label($r->invoice_status)); ?></span></td></tr>
            <?php endif; endforeach; endif;
        if (!$has_unpaid): ?><tr><td colspan="4">No undrafted invoices.</td></tr><?php endif; ?></tbody>
    </table></div></section>
    <?php return ob_get_clean();
}

add_shortcode('woodcrest_manager_header', 'woodcrest_manager_header_html');
add_shortcode('woodcrest_manager_banner', 'woodcrest_manager_banner_html');
add_shortcode('woodcrest_manager_action_grid', 'woodcrest_manager_action_grid_html');
add_shortcode('woodcrest_manager_tenant_table', 'woodcrest_manager_tenant_table_html');
function woodcrest_manager_usage_table_html() {
    
    
    return woodcrest_manager_monthly_utility_costs_html() . woodcrest_manager_meter_readings_html();
}
add_shortcode('woodcrest_manager_usage_table', 'woodcrest_manager_usage_table_html');
add_shortcode('woodcrest_manager_billing_table', 'woodcrest_manager_billing_table_html');
add_shortcode('woodcrest_manager_summary_stats', 'woodcrest_manager_summary_stats_html');
add_shortcode('woodcrest_manager_unpaid_bills', 'woodcrest_manager_unpaid_bills_html');


function woodcrest_manager_dashboard_html() {
    ob_start();
    echo woodcrest_manager_header_html();
    echo woodcrest_manager_banner_html();
    echo woodcrest_manager_action_grid_html();
    echo woodcrest_manager_summary_stats_html();
    echo woodcrest_manager_tenant_table_html();
    echo woodcrest_manager_usage_summary_table_html();
    echo woodcrest_manager_unpaid_bills_html();
    return ob_get_clean();
}
add_shortcode('woodcrest_manager_dashboard', 'woodcrest_manager_dashboard_html');

