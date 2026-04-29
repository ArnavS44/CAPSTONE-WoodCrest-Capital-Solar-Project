<?php
if (!defined('ABSPATH')) exit;

/*helpers*/

function woodcrest_get_tenant_id() {
    if (isset($_GET['tenant_id'])) {
        return intval($_GET['tenant_id']);
    }
    return get_current_user_id();
}

function woodcrest_get_selected_meter_id($type = 'ELECTRIC') {
    $key = strtolower($type) . '_meter_id';
    return isset($_GET[$key]) ? intval($_GET[$key]) : 0;
}

function woodcrest_get_tenant($tenant_id) {
    global $woodcrest_db;
    return $woodcrest_db->get_row($woodcrest_db->prepare("
        SELECT ID AS user_id, display_name AS full_name, user_email AS email
        FROM {$woodcrest_db->app_user}
        WHERE ID = %d
    ", $tenant_id));
}

function woodcrest_get_tenant_invoices($tenant_id) {
    global $woodcrest_db;
    return $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT invoice_id, invoice_number, property_id, suite_id, period_start, period_end, total_amount, status
        FROM {$woodcrest_db->invoice}
        WHERE tenant_user_id = %d
        ORDER BY period_end DESC
    ", $tenant_id));
}

function woodcrest_get_tenant_properties($tenant_id) {
    global $woodcrest_db;
    return $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT 
            p.property_id, p.property_name, p.address_line1, p.city, p.state, p.zip,
            s.suite_id, s.suite_label,
            COUNT(DISTINCT mr.meter_id) AS assigned_meters
        FROM {$woodcrest_db->suite_occupancy} so
        JOIN {$woodcrest_db->suite} s ON so.suite_id = s.suite_id
        JOIN {$woodcrest_db->property} p ON s.property_id = p.property_id
        LEFT JOIN {$woodcrest_db->meter_responsibility} mr 
            ON mr.tenant_user_id = so.tenant_user_id
           AND mr.suite_id = s.suite_id
           AND (mr.end_date IS NULL OR mr.end_date >= CURDATE())
        WHERE so.tenant_user_id = %d
          AND (so.end_date IS NULL OR so.end_date >= CURDATE())
        GROUP BY p.property_id, p.property_name, p.address_line1, p.city, p.state, p.zip, s.suite_id, s.suite_label
        ORDER BY p.property_name ASC
    ", $tenant_id));
}

function woodcrest_get_tenant_meters($tenant_id, $utility_type = 'ELECTRIC') {
    global $woodcrest_db;
    return $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT 
            m.meter_id, m.meter_number, m.meter_name, m.utility_type, m.meter_type, m.meter_size,
            p.property_name, s.suite_label
        FROM {$woodcrest_db->meter_responsibility} mr
        JOIN {$woodcrest_db->meter} m ON mr.meter_id = m.meter_id
        LEFT JOIN {$woodcrest_db->property} p ON m.property_id = p.property_id
        LEFT JOIN {$woodcrest_db->suite} s ON mr.suite_id = s.suite_id
        WHERE mr.tenant_user_id = %d
          AND m.utility_type = %s
          AND (mr.end_date IS NULL OR mr.end_date >= CURDATE())
        ORDER BY p.property_name, m.meter_number
    ", $tenant_id, $utility_type));
}

function woodcrest_get_meter_readings($meter_id, $limit = 6) {
    global $woodcrest_db;
    return $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT reading_date, current_read, previous_read, usage_amount, usage_unit
        FROM {$woodcrest_db->utility_reading}
        WHERE meter_id = %d
        ORDER BY reading_date DESC
        LIMIT %d
    ", $meter_id, $limit));
}

function woodcrest_get_selected_meter($meters, $selected_meter_id) {
    if (!$selected_meter_id && !empty($meters)) return $meters[0];
    foreach ($meters as $meter) {
        if ((int)$meter->meter_id === (int)$selected_meter_id) return $meter;
    }
    return !empty($meters) ? $meters[0] : null;
}

function woodcrest_get_invoice_costs_by_line_type($tenant_id, $line_type, $limit = 2) {
    global $woodcrest_db;
    return $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT i.invoice_id, i.invoice_number, i.period_end, SUM(li.amount) AS total_amount
        FROM {$woodcrest_db->invoice} i
        JOIN {$woodcrest_db->invoice_line_item} li ON i.invoice_id = li.invoice_id
        WHERE i.tenant_user_id = %d AND li.line_type = %s
        GROUP BY i.invoice_id, i.invoice_number, i.period_end
        ORDER BY i.period_end DESC LIMIT %d
    ", $tenant_id, $line_type, $limit));
}

function woodcrest_format_money_change($diff) {
    if ($diff === null) return '—';
    return ($diff >= 0 ? '+$' : '-$') . number_format(abs($diff), 2);
}

function woodcrest_change_class($diff, $increase_is_bad = true) {
    if ($diff === null) return 'is-neutral';
    if ($diff > 0) return $increase_is_bad ? 'is-negative' : 'is-positive';
    if ($diff < 0) return $increase_is_bad ? 'is-positive' : 'is-negative';
    return 'is-neutral';
}

/* tenant page code*/

function woodcrest_tenant_header_html() {
    $tenant_id = woodcrest_get_tenant_id();
    $tenant = woodcrest_get_tenant($tenant_id);
    if (!$tenant) return '<p>Tenant not found.</p>';
    ob_start(); ?>
    <div class="woodcrest-header-box">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="woodcrest-header-badge">Tenant</div>
                <h1><?php echo esc_html($tenant->full_name); ?></h1>
                <p><?php echo esc_html($tenant->email); ?></p>
            </div>
            <a href="<?php echo esc_url(wp_logout_url(site_url('wp-login.php'))); ?>" class="woodcrest-logout-link">Sign Out</a>
        </div>
    </div>
    <?php return ob_get_clean();
}

function woodcrest_tenant_summary_html() {
    $tenant_id = woodcrest_get_tenant_id();
    $invoices = woodcrest_get_tenant_invoices($tenant_id);
    $current = $invoices[0] ?? null;
    $prev = $invoices[1] ?? null;
    $curr_total = $current ? (float)$current->total_amount : 0;
    $prev_total = $prev ? (float)$prev->total_amount : 0;
    $diff = ($current && $prev) ? $curr_total - $prev_total : null;
    ob_start(); ?>
    <div class="woodcrest-stats">
        <div class="woodcrest-stat-card"><span class="woodcrest-stat-label">Current Balance</span><span class="woodcrest-stat-value">$<?php echo number_format($curr_total, 2); ?></span></div>
        <div class="woodcrest-stat-card"><span class="woodcrest-stat-label">Previous Month</span><span class="woodcrest-stat-value">$<?php echo number_format($prev_total, 2); ?></span></div>
        <div class="woodcrest-stat-card"><span class="woodcrest-stat-label">Monthly Change</span><span class="woodcrest-stat-value <?php echo esc_attr(woodcrest_change_class($diff)); ?>"><?php echo esc_html(woodcrest_format_money_change($diff)); ?></span></div>
    </div>
    <?php return ob_get_clean();
}

function woodcrest_tenant_properties_html() {
    $tenant_id = woodcrest_get_tenant_id();
    $properties = woodcrest_get_tenant_properties($tenant_id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Properties</h2></div><div class="woodcrest-table-wrap"><table class="woodcrest-table"><thead><tr><th>Property</th><th>Address</th><th>Suite</th><th>Meters</th></tr></thead><tbody>
    <?php if (!empty($properties)): foreach ($properties as $p): ?>
        <tr><td><?php echo esc_html($p->property_name); ?></td><td><?php echo esc_html($p->address_line1); ?></td><td><?php echo esc_html($p->suite_label); ?></td><td><?php echo esc_html($p->assigned_meters); ?></td></tr>
    <?php endforeach; else: ?><tr><td colspan="4">No properties found.</td></tr><?php endif; ?>
    </tbody></table></div></section>
    <?php return ob_get_clean();
}

function woodcrest_tenant_electric_html() {
    $tenant_id = woodcrest_get_tenant_id();
    $selected_id = woodcrest_get_selected_meter_id('electric');
    $meters = woodcrest_get_tenant_meters($tenant_id, 'ELECTRIC');
    $selected = woodcrest_get_selected_meter($meters, $selected_id);
    $readings = $selected ? woodcrest_get_meter_readings($selected->meter_id) : [];
    ob_start(); ?>
    <section class="woodcrest-card"><div class="woodcrest-card-header"><h2>Electric</h2></div>
    <?php if (!empty($meters)): ?>
        <form method="get" class="woodcrest-meter-form">
            <?php if (isset($_GET['tenant_id'])): ?><input type="hidden" name="tenant_id" value="<?php echo esc_attr($tenant_id); ?>"><?php endif; ?>
            <select name="electric_meter_id" onchange="this.form.submit()">
                <?php foreach ($meters as $m): ?><option value="<?php echo esc_attr($m->meter_id); ?>" <?php selected($selected ? $selected->meter_id : 0, $m->meter_id); ?>><?php echo esc_html($m->property_name . ' - ' . $m->meter_number); ?></option><?php endforeach; ?>
            </select>
        </form>
        <?php if ($selected): ?>
            <div class="woodcrest-table-wrap"><table class="woodcrest-table"><thead><tr><th>Date</th><th>Read</th><th>Usage</th></tr></thead><tbody>
            <?php foreach ($readings as $r): ?><tr><td><?php echo esc_html($r->reading_date); ?></td><td><?php echo esc_html($r->current_read); ?></td><td><?php echo esc_html($r->usage_amount); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    <?php else: ?><p>No electric meters.</p><?php endif; ?>
    </section>
    <?php return ob_get_clean();
}

function woodcrest_tenant_water_html() {
    $tenant_id = woodcrest_get_tenant_id();
    $selected_id = woodcrest_get_selected_meter_id('water');
    $meters = woodcrest_get_tenant_meters($tenant_id, 'WATER');
    $selected = woodcrest_get_selected_meter($meters, $selected_id);
    $readings = $selected ? woodcrest_get_meter_readings($selected->meter_id) : [];
    ob_start(); ?>
    <section class="woodcrest-card"><div class="woodcrest-card-header"><h2>Water</h2></div>
    <?php if (!empty($meters)): ?>
        <form method="get" class="woodcrest-meter-form">
            <?php if (isset($_GET['tenant_id'])): ?><input type="hidden" name="tenant_id" value="<?php echo esc_attr($tenant_id); ?>"><?php endif; ?>
            <select name="water_meter_id" onchange="this.form.submit()">
                <?php foreach ($meters as $m): ?><option value="<?php echo esc_attr($m->meter_id); ?>" <?php selected($selected ? $selected->meter_id : 0, $m->meter_id); ?>><?php echo esc_html($m->property_name . ' - ' . $m->meter_number); ?></option><?php endforeach; ?>
            </select>
        </form>
        <?php if ($selected): ?>
            <div class="woodcrest-table-wrap"><table class="woodcrest-table"><thead><tr><th>Date</th><th>Read</th><th>Usage</th></tr></thead><tbody>
            <?php foreach ($readings as $r): ?><tr><td><?php echo esc_html($r->reading_date); ?></td><td><?php echo esc_html($r->current_read); ?></td><td><?php echo esc_html($r->usage_amount); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    <?php else: ?><p>No water meters.</p><?php endif; ?>
    </section>
    <?php return ob_get_clean();
}

function woodcrest_tenant_invoices_html() {
    $tenant_id = woodcrest_get_tenant_id();
    $invoices = woodcrest_get_tenant_invoices($tenant_id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Invoices</h2></div><div class="woodcrest-table-wrap"><table class="woodcrest-table"><thead><tr><th>#</th><th>Period</th><th>Total</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($invoices as $i): ?><tr><td><?php echo esc_html($i->invoice_number); ?></td><td><?php echo esc_html($i->period_start . ' - ' . $i->period_end); ?></td><td>$<?php echo number_format($i->total_amount, 2); ?></td><td><span class="woodcrest-badge status-<?php echo strtolower($i->status); ?>"><?php echo esc_html($i->status); ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
    <?php return ob_get_clean();
}

add_shortcode('woodcrest_tenant_header', 'woodcrest_tenant_header_html');
add_shortcode('woodcrest_tenant_summary', 'woodcrest_tenant_summary_html');
add_shortcode('woodcrest_tenant_properties', 'woodcrest_tenant_properties_html');
add_shortcode('woodcrest_tenant_electric', 'woodcrest_tenant_electric_html');
add_shortcode('woodcrest_tenant_water', 'woodcrest_tenant_water_html');
add_shortcode('woodcrest_tenant_invoices', 'woodcrest_tenant_invoices_html');

/*pm helpers */

function woodcrest_get_manager_id() {
    return isset($_GET['manager_id']) ? intval($_GET['manager_id']) : get_current_user_id();
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
    ", $manager_id));
}

function woodcrest_get_manager_billing_totals($manager_id) {
    global $woodcrest_db;
    $rows = $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT period_end, SUM(total_amount) AS total_billed
        FROM {$woodcrest_db->invoice} i
        JOIN {$woodcrest_db->user_property_access} upa ON i.property_id = upa.property_id
        WHERE upa.user_id = %d AND upa.access_scope = 'PROPERTY_MANAGER'
        GROUP BY period_end ORDER BY period_end DESC LIMIT 2
    ", $manager_id));
    $curr = isset($rows[0]) ? (float)$rows[0]->total_billed : 0;
    $prev = isset($rows[1]) ? (float)$rows[1]->total_billed : 0;
    return (object)['current_total' => $curr, 'previous_total' => $prev, 'diff_total' => $curr - $prev];
}

function woodcrest_get_manager_tenant_rows($manager_id) {
    global $woodcrest_db;
    return $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT u.ID AS tenant_user_id, u.display_name AS tenant_name, p.property_name, p.city, p.state, s.suite_label,
               (SELECT total_amount FROM {$woodcrest_db->invoice} WHERE tenant_user_id = u.ID AND property_id = p.property_id ORDER BY period_end DESC LIMIT 1) AS current_month_cost,
               (SELECT total_amount FROM {$woodcrest_db->invoice} WHERE tenant_user_id = u.ID AND property_id = p.property_id ORDER BY period_end DESC LIMIT 1 OFFSET 1) AS previous_month_cost,
               (SELECT status FROM {$woodcrest_db->invoice} WHERE tenant_user_id = u.ID AND property_id = p.property_id ORDER BY period_end DESC LIMIT 1) AS invoice_status,
               (SELECT meter_number FROM {$woodcrest_db->meter} m JOIN {$woodcrest_db->meter_responsibility} mr ON m.meter_id = mr.meter_id WHERE mr.tenant_user_id = u.ID AND m.utility_type = 'ELECTRIC' LIMIT 1) AS electric_meter_number,
               (SELECT usage_amount FROM {$woodcrest_db->utility_reading} ur JOIN {$woodcrest_db->meter} m ON ur.meter_id = m.meter_id JOIN {$woodcrest_db->meter_responsibility} mr ON m.meter_id = mr.meter_id WHERE mr.tenant_user_id = u.ID AND m.utility_type = 'ELECTRIC' ORDER BY reading_date DESC LIMIT 1) AS current_kwh,
               (SELECT meter_number FROM {$woodcrest_db->meter} m JOIN {$woodcrest_db->meter_responsibility} mr ON m.meter_id = mr.meter_id WHERE mr.tenant_user_id = u.ID AND m.utility_type = 'WATER' LIMIT 1) AS water_meter_number,
               (SELECT usage_amount FROM {$woodcrest_db->utility_reading} ur JOIN {$woodcrest_db->meter} m ON ur.meter_id = m.meter_id JOIN {$woodcrest_db->meter_responsibility} mr ON m.meter_id = mr.meter_id WHERE mr.tenant_user_id = u.ID AND m.utility_type = 'WATER' ORDER BY reading_date DESC LIMIT 1) AS current_water_usage
        FROM {$woodcrest_db->user_property_access} upa
        JOIN {$woodcrest_db->property} p ON upa.property_id = p.property_id
        JOIN {$woodcrest_db->suite} s ON s.property_id = p.property_id
        JOIN {$woodcrest_db->suite_occupancy} so ON so.suite_id = s.suite_id AND (so.end_date IS NULL OR so.end_date >= CURDATE())
        JOIN {$woodcrest_db->app_user} u ON u.ID = so.tenant_user_id
        WHERE upa.user_id = %d AND upa.access_scope = 'PROPERTY_MANAGER'
        ORDER BY p.property_name, s.suite_label
    ", $manager_id));
}



// dashboard header
function woodcrest_manager_header_html() {
    $manager_id = woodcrest_get_manager_id();
    $manager = woodcrest_get_manager($manager_id);
    if (!$manager) return '<p>Manager not found.</p>';
    ob_start(); ?>
    <div class="woodcrest-header-box">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="woodcrest-header-badge manager">Property Manager</div>
                <h1><?php echo esc_html($manager->full_name); ?></h1>
                <p><?php echo esc_html($manager->email); ?></p>
            </div>
            <a href="<?php echo esc_url(wp_logout_url(site_url('wp-login.php'))); ?>" class="woodcrest-logout-link">Sign Out</a>
        </div>
    </div>
    <?php return ob_get_clean();
}

// dashboard summary
function woodcrest_manager_banner_html() {
    return '<div class="woodcrest-manager-banner"><div class="woodcrest-manager-banner-title">DASHBOARD / UTILITY SUMMARY</div></div>';
}

// Individual Card Shortcodes
function woodcrest_card_active_accounts_html() {
    $summary = woodcrest_get_manager_summary(woodcrest_get_manager_id());
    return '<div class="woodcrest-manager-action-card"><span class="woodcrest-manager-action-number">' . ($summary->total_active_tenants ?: 0) . '</span><span class="woodcrest-manager-action-label">Active Accounts</span></div>';
}

function woodcrest_card_unpaid_bills_html() {
    $rows = woodcrest_get_manager_tenant_rows(woodcrest_get_manager_id());
    $count = 0;
    if ($rows) { foreach ($rows as $r) { if (!empty($r->invoice_status) && $r->invoice_status !== 'CONFIRMED') $count++; } }
    return '<div class="woodcrest-manager-action-card"><span class="woodcrest-manager-action-number">' . $count . '</span><span class="woodcrest-manager-action-label">Tenant Unpaid Bills</span></div>';
}

function woodcrest_card_invoices_html() {
    $billing = woodcrest_get_manager_billing_totals(woodcrest_get_manager_id());
    return '<div class="woodcrest-manager-action-card"><span class="woodcrest-manager-action-number">$' . number_format($billing->current_total, 0) . '</span><span class="woodcrest-manager-action-label">Tenant Invoices</span></div>';
}

function woodcrest_card_usage_html() {
    $summary = woodcrest_get_manager_summary(woodcrest_get_manager_id());
    return '<div class="woodcrest-manager-action-card"><span class="woodcrest-manager-action-number">' . ($summary->electric_meters ?: 0) . '</span><span class="woodcrest-manager-action-label">Usage Overview</span></div>';
}

function woodcrest_card_billing_html() {
    $billing = woodcrest_get_manager_billing_totals(woodcrest_get_manager_id());
    return '<div class="woodcrest-manager-action-card"><span class="woodcrest-manager-action-number">' . woodcrest_format_money_change($billing->diff_total) . '</span><span class="woodcrest-manager-action-label">Billing Info</span></div>';
}

function woodcrest_card_properties_html() {
    $summary = woodcrest_get_manager_summary(woodcrest_get_manager_id());
    return '<div class="woodcrest-manager-action-card"><span class="woodcrest-manager-action-number">' . ($summary->total_properties ?: 0) . '</span><span class="woodcrest-manager-action-label">Property Management</span></div>';
}

add_shortcode('woodcrest_card_active_accounts', 'woodcrest_card_active_accounts_html');
add_shortcode('woodcrest_card_unpaid_bills', 'woodcrest_card_unpaid_bills_html');
add_shortcode('woodcrest_card_invoices', 'woodcrest_card_invoices_html');
add_shortcode('woodcrest_card_usage', 'woodcrest_card_usage_html');
add_shortcode('woodcrest_card_billing', 'woodcrest_card_billing_html');
add_shortcode('woodcrest_card_properties', 'woodcrest_card_properties_html');

// pm grid wrapper
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

// Tenant Management Table (With Search)
function woodcrest_manager_tenant_table_html() {
    $id = woodcrest_get_manager_id();
    $rows = woodcrest_get_manager_tenant_rows($id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-manager-panel woodcrest-card-full">
        <div class="woodcrest-card-header"><h2>Tenant Management Table</h2></div>
        <div class="woodcrest-manager-toolbar"><input type="text" id="woodcrest-tenant-search" class="woodcrest-search-input" placeholder="Search tenants, properties, suites, city..."></div>
        <div class="woodcrest-table-wrap"><table class="woodcrest-table" id="woodcrest-manager-tenant-table">
            <thead><tr><th>Tenant</th><th>Property</th><th>Suite</th><th>City</th><th>State</th><th>Current Cost</th><th>Previous Cost</th><th>Monthly Diff</th><th>Invoiced?</th><th>Actions</th></tr></thead>
            <tbody><?php if ($rows): foreach ($rows as $r): 
                $diff = ($r->current_month_cost !== null && $r->previous_month_cost !== null) ? (float)$r->current_month_cost - (float)$r->previous_month_cost : null; 
                $isInvoiced = in_array($r->invoice_status, ['CONFIRMED', 'SENT']); ?>
                <tr class="woodcrest-search-row">
                    <td><strong><?php echo esc_html($r->tenant_name); ?></strong></td>
                    <td><?php echo esc_html($r->property_name); ?></td>
                    <td><?php echo esc_html($r->suite_label); ?></td>
                    <td><?php echo esc_html($r->city); ?></td>
                    <td><?php echo esc_html($r->state); ?></td>
                    <td>$<?php echo number_format((float)$r->current_month_cost, 2); ?></td>
                    <td>$<?php echo number_format((float)$r->previous_month_cost, 2); ?></td>
                    <td class="<?php echo woodcrest_change_class($diff); ?>"><?php echo woodcrest_format_money_change($diff); ?></td>
                    <td><?php echo $isInvoiced ? '<span class="woodcrest-badge status-confirmed">Yes</span>' : '<span class="woodcrest-badge">No</span>'; ?></td>
                    <td>
                        <div class="woodcrest-action-buttons">
                            <a class="woodcrest-action-btn" href="<?php echo home_url('/tenant-user-page/?tenant_id=' . $r->tenant_user_id); ?>">Tenant View</a>
                            <a class="woodcrest-action-btn secondary" href="#">Generate Invoice</a>
                            <a class="woodcrest-action-btn secondary" href="#">Download Invoice</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?><tr><td colspan="9">No tenants.</td></tr><?php endif; ?></tbody>
        </table></div>
    </section>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        const input = document.getElementById('woodcrest-tenant-search');
        const table = document.getElementById('woodcrest-manager-tenant-table');
        if (input && table) {
            input.addEventListener('keyup', function(){
                const filter = input.value.toLowerCase();
                const rows = table.querySelectorAll('tbody tr.woodcrest-search-row');
                rows.forEach(r => { r.style.display = r.innerText.toLowerCase().includes(filter) ? '' : 'none'; });
            });
        }
    });
    </script>
    <?php return ob_get_clean();
}

//  Usage Tracking Table
function woodcrest_manager_usage_table_html() {
    $id = woodcrest_get_manager_id();
    $rows = woodcrest_get_manager_tenant_rows($id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-manager-panel"><div class="woodcrest-card-header"><h2>Usage Tracking Table</h2></div><div class="woodcrest-table-wrap"><table class="woodcrest-table">
        <thead><tr><th>Tenant</th><th>Property</th><th>Electric Meter</th><th>kWh</th><th>Water Meter</th><th>Usage</th></tr></thead>
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

// Billing Management Table
function woodcrest_manager_billing_table_html() {
    $id = woodcrest_get_manager_id();
    $rows = woodcrest_get_manager_tenant_rows($id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-manager-panel"><div class="woodcrest-card-header"><h2>Billing Management Table</h2></div><div class="woodcrest-table-wrap"><table class="woodcrest-table">
        <thead><tr><th>Tenant</th><th>Property</th><th>Current Cost</th><th>Previous Cost</th><th>Status</th></tr></thead>
        <tbody><?php if ($rows): foreach ($rows as $r): ?>
            <tr>
                <td><strong><?php echo esc_html($r->tenant_name); ?></strong></td>
                <td><?php echo esc_html($r->property_name); ?></td>
                <td>$<?php echo number_format((float)$r->current_month_cost, 2); ?></td>
                <td>$<?php echo number_format((float)$r->previous_month_cost, 2); ?></td>
                <td><span class="woodcrest-badge status-<?php echo strtolower($r->invoice_status ?: 'draft'); ?>"><?php echo esc_html($r->invoice_status ?: 'DRAFT'); ?></span></td>
            </tr>
        <?php endforeach; else: ?><tr><td colspan="5">No billing records.</td></tr><?php endif; ?></tbody>
    </table></div></section>
    <?php return ob_get_clean();
}

//Manage Dashboard (Summary stats)
function woodcrest_manager_summary_stats_html() {
    $id = woodcrest_get_manager_id();
    $manager = woodcrest_get_manager($id);
    $s = woodcrest_get_manager_summary($id);
    if (!$s) $s = (object)['total_properties'=>0,'electric_meters'=>0,'water_meters'=>0];
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-manager-panel"><div class="woodcrest-card-header"><h2>Manage Dashboard</h2></div><div class="woodcrest-manager-manage-grid">
        <div class="woodcrest-mini-stat"><span>Manager</span><strong><?php echo esc_html($manager->full_name); ?></strong></div>
        <div class="woodcrest-mini-stat"><span>Managed Properties</span><strong><?php echo $s->total_properties; ?></strong></div>
        <div class="woodcrest-mini-stat"><span>Electric Meters</span><strong><?php echo $s->electric_meters; ?></strong></div>
        <div class="woodcrest-mini-stat"><span>Water Meters</span><strong><?php echo $s->water_meters; ?></strong></div>
    </div></section>
    <?php return ob_get_clean();
}

//Unpaid / Non-Confirmed Bills
function woodcrest_manager_unpaid_bills_html() {
    $id = woodcrest_get_manager_id();
    $rows = woodcrest_get_manager_tenant_rows($id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-manager-panel"><div class="woodcrest-card-header"><h2>Unpaid / Non-Confirmed Bills</h2></div><div class="woodcrest-table-wrap"><table class="woodcrest-table">
        <thead><tr><th>Tenant</th><th>Property</th><th>Cost</th><th>Status</th></tr></thead>
        <tbody><?php 
        $has_unpaid = false;
        if ($rows): foreach ($rows as $r): 
            if ($r->invoice_status !== 'CONFIRMED'): $has_unpaid = true; ?>
                <tr><td><strong><?php echo esc_html($r->tenant_name); ?></strong></td><td><?php echo esc_html($r->property_name); ?></td><td>$<?php echo number_format((float)$r->current_month_cost, 2); ?></td><td><span class="woodcrest-badge status-<?php echo strtolower($r->invoice_status ?: 'draft'); ?>"><?php echo esc_html($r->invoice_status ?: 'DRAFT'); ?></span></td></tr>
            <?php endif; endforeach; endif;
        if (!$has_unpaid): ?><tr><td colspan="4">No unpaid bills.</td></tr><?php endif; ?></tbody>
    </table></div></section>
    <?php return ob_get_clean();
}

add_shortcode('woodcrest_manager_header', 'woodcrest_manager_header_html');
add_shortcode('woodcrest_manager_banner', 'woodcrest_manager_banner_html');
add_shortcode('woodcrest_manager_action_grid', 'woodcrest_manager_action_grid_html');
add_shortcode('woodcrest_manager_tenant_table', 'woodcrest_manager_tenant_table_html');
add_shortcode('woodcrest_manager_usage_table', 'woodcrest_manager_usage_table_html');
add_shortcode('woodcrest_manager_billing_table', 'woodcrest_manager_billing_table_html');
add_shortcode('woodcrest_manager_summary_stats', 'woodcrest_manager_summary_stats_html');
add_shortcode('woodcrest_manager_unpaid_bills', 'woodcrest_manager_unpaid_bills_html');

// Manager Dashboard Wrapper
function woodcrest_manager_dashboard_html() {
    ob_start();
    echo woodcrest_manager_header_html();
    echo woodcrest_manager_banner_html();
    echo woodcrest_manager_action_grid_html();
    echo woodcrest_manager_summary_stats_html();
    echo woodcrest_manager_tenant_table_html();
    echo woodcrest_manager_usage_table_html();
    echo woodcrest_manager_unpaid_bills_html();
    return ob_get_clean();
}
add_shortcode('woodcrest_manager_dashboard', 'woodcrest_manager_dashboard_html');

/* login*/

add_shortcode('woodcrest_login_form', function() {
    ob_start(); ?>
    <div class="woodcrest-login-container">
        <div class="woodcrest-login-card">
            <div class="woodcrest-login-app-name">
                <span>WOODCREST</span>
                <span class="utility-box">Utility Management Tool</span>
            </div>
            
            <div class="woodcrest-login-toggle-wrapper">
                <button type="button" class="toggle-btn active tenant" data-role="tenant">Tenant</button>
                <button type="button" class="toggle-btn manager" data-role="manager">Property Manager</button>
            </div>
            
            <div class="woodcrest-login-header">
                <h2 id="login-title">Tenant Login</h2>
            </div>

            <form method="post" action="<?php echo esc_url(site_url('wp-login.php')); ?>" id="woodcrest-login-form">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url(home_url('/dashboard-router/')); ?>">
                
                <div class="woodcrest-form-group">
                    <label for="log">EMAIL ADDRESS or USERNAME</label>
                    <input type="text" name="log" id="log" placeholder="Email or Username" required>
                </div>

                <div class="woodcrest-form-group">
                    <label for="pwd">Password</label>
                    <input type="password" name="pwd" id="pwd" placeholder="••••••••" required>
                </div>

                <div class="woodcrest-form-footer">
                    <label class="remember-me">
                        <input name="rememberme" type="checkbox" value="forever"> Remember Me
                    </label>
                    <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" class="woodcrest-submit-btn" id="submit-btn">Sign in as Tenant</button>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const buttons = document.querySelectorAll('.toggle-btn');
        const title = document.getElementById('login-title');
        const submitBtn = document.getElementById('submit-btn');
        const loginCard = document.querySelector('.woodcrest-login-card');

        buttons.forEach(btn => {
            btn.addEventListener('click', function() {
                buttons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const role = this.getAttribute('data-role');
                if (role === 'manager') {
                    title.innerText = 'Property Manager Login';
                    submitBtn.innerText = 'Sign In as Property Manager';
                    submitBtn.className = 'woodcrest-submit-btn manager-btn';
                    loginCard.style.borderColor = '#16a34a';
                } else {
                    title.innerText = 'Tenant Login';
                    submitBtn.innerText = 'Sign In as Tenant';
                    submitBtn.className = 'woodcrest-submit-btn tenant-btn';
                    loginCard.style.borderColor = '#2563eb';
                }
            });
        });
    });
    </script>
    <?php return ob_get_clean();
});

add_shortcode('woodcrest_dashboard_router', function() {
    if (!is_user_logged_in()) { wp_safe_redirect(site_url('wp-login.php')); exit; }
    
    if (current_user_can('manage_options')) {
        wp_safe_redirect(home_url('/admin-user-page/'));
        exit;
    }

    $user = wp_get_current_user();
    global $woodcrest_db;
    $access = $woodcrest_db->get_row($woodcrest_db->prepare("SELECT access_scope FROM {$woodcrest_db->user_property_access} WHERE user_id = %d LIMIT 1", $user->ID));
    if ($access && $access->access_scope === 'PROPERTY_MANAGER') { wp_safe_redirect(home_url('/property-manager-user-page/')); exit; }
    wp_safe_redirect(home_url('/tenant-user-page/')); exit;
});

/* admin page*/

function woodcrest_admin_demo_portal_html() {
    if (!current_user_can('manage_options')) {
        return '<div class="woodcrest-card"><strong>Access Denied.</strong></div>';
    }

    global $woodcrest_db;

    $users = $woodcrest_db->get_results("
        SELECT 
            u.ID, 
            u.display_name, 
            u.user_email,
            (SELECT COUNT(*) FROM {$woodcrest_db->user_property_access} upa WHERE upa.user_id = u.ID AND upa.access_scope = 'PROPERTY_MANAGER') as is_manager,
            (SELECT COUNT(*) FROM {$woodcrest_db->suite_occupancy} so WHERE so.tenant_user_id = u.ID) as is_tenant
        FROM {$woodcrest_db->app_user} u
        ORDER BY u.display_name ASC
    ");

    ob_start();
    ?>
    <div class="woodcrest-page">
        <div class="woodcrest-header-box">
            <div class="woodcrest-header-badge" style="background:#f50000;">Admin</div>
            <h1>User Directory</h1>
            <p>For testing the views of different registered users.</p>
        </div>

        <section class="woodcrest-card woodcrest-card-wide">
            <div class="woodcrest-card-header">
                <h2>All System Users</h2>
            </div>
            
            <div class="woodcrest-manager-toolbar">
                <input 
                    type="text" 
                    id="woodcrest-admin-user-search" 
                    class="woodcrest-search-input" 
                    placeholder="Search users by name or email..."
                >
            </div>

            <div class="woodcrest-table-wrap">
                <table class="woodcrest-table" id="woodcrest-admin-demo-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Roles</th>
                            <th>View Page</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): 
                            $roles = [];
                            if ($u->is_manager) $roles[] = '<span class="woodcrest-badge status-sent">MANAGER</span>';
                            if ($u->is_tenant) $roles[] = '<span class="woodcrest-badge status-confirmed">TENANT</span>';
                            if (empty($roles)) $roles[] = '<span class="woodcrest-badge">NULL</span>';
                        ?>
                            <tr class="woodcrest-user-row">
                                <td style="font-family:monospace; opacity:0.6;">#<?php echo esc_html($u->ID); ?></td>
                                <td><strong><?php echo esc_html($u->display_name); ?></strong></td>
                                <td><?php echo esc_html($u->user_email); ?></td>
                                <td><?php echo implode(' ', $roles); ?></td>
                                <td>
                                    <div class="woodcrest-action-buttons">
                                        <?php if ($u->is_manager): ?>
                                            <a class="woodcrest-action-btn" href="<?php echo esc_url(home_url('/property-manager-user-page/?manager_id=' . $u->ID)); ?>">Manager Portal</a>
                                        <?php endif; ?>
                                        <?php if ($u->is_tenant): ?>
                                            <a class="woodcrest-action-btn secondary" href="<?php echo esc_url(home_url('/tenant-user-page/?tenant_id=' . $u->ID)); ?>">Tenant Portal</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('woodcrest-admin-user-search');
        const userTable = document.getElementById('woodcrest-admin-demo-table');
        if (searchInput && userTable) {
            searchInput.addEventListener('keyup', function() {
                const term = searchInput.value.toLowerCase();
                const rows = userTable.querySelectorAll('tbody tr.woodcrest-user-row');
                rows.forEach(row => {
                    row.style.display = row.innerText.toLowerCase().includes(term) ? '' : 'none';
                });
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('woodcrest_admin_demo_portal', 'woodcrest_admin_demo_portal_html');

add_action('template_redirect', function() {
    $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    if ($path === 'dashboard-router') {
        if (!is_user_logged_in()) {
            wp_safe_redirect(site_url('wp-login.php'));
            exit;
        }

        if (current_user_can('manage_options')) {
            wp_safe_redirect(home_url('/admin-user-page/'));
            exit;
        }

        $user = wp_get_current_user();
        global $woodcrest_db;

        // Check PM access
        $access = $woodcrest_db->get_row($woodcrest_db->prepare(
            "SELECT access_scope FROM {$woodcrest_db->user_property_access} WHERE user_id = %d LIMIT 1", 
            $user->ID
        ));

        if ($access && $access->access_scope === 'PROPERTY_MANAGER') {
            wp_safe_redirect(home_url('/property-manager-user-page/'));
        } else {
            wp_safe_redirect(home_url('/tenant-user-page/'));
        }
        exit;
    }
});

