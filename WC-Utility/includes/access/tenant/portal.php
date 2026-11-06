<?php
if (!defined('ABSPATH')) exit;



function woodcrest_get_tenant_id() {
    return woodcrest_v23_primary_tenant_id(woodcrest_fe_effective_tenant_id());
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
    $viewer = woodcrest_fe_effective_tenant_id();
    $access = woodcrest_v23_suite_access_ready() ? woodcrest_v24_invoice_scope_sql('i',$viewer) : 'i.tenant_user_id = '.absint($viewer);
    return $woodcrest_db->get_results("
        SELECT i.invoice_id, i.invoice_number, i.property_id, i.suite_id, i.period_start, i.period_end, i.total_amount, i.status
        FROM {$woodcrest_db->invoice} i WHERE {$access}
        ORDER BY i.period_end DESC
    ");
}

function woodcrest_get_tenant_properties($tenant_id) {
    global $woodcrest_db;
    $viewer = woodcrest_fe_effective_tenant_id();
    $suite_ids = array_values(array_unique(array_merge(woodcrest_v23_visible_suite_ids($viewer),woodcrest_v24_historical_suite_ids($viewer))));
    if (!$suite_ids) return [];
    $ids = implode(',', array_map('intval',$suite_ids));
    $properties = $woodcrest_db->get_results("
        SELECT p.property_id,p.property_name,p.address_line1,p.city,p.state,p.zip,
               s.suite_id,s.suite_label,0 AS assigned_meters
        FROM {$woodcrest_db->suite} s JOIN {$woodcrest_db->property} p ON p.property_id=s.property_id
        WHERE s.suite_id IN ({$ids}) ORDER BY p.property_name,s.suite_label
    ");
    $meter_ids = [];
    foreach (['WATER','ELECTRIC'] as $utility) foreach (woodcrest_get_tenant_meters($tenant_id,$utility) as $meter) {
        $meter_ids[(int)$meter->suite_id][(int)$meter->meter_id] = true;
    }
    foreach ((array)$properties as $property) $property->assigned_meters = count($meter_ids[(int)$property->suite_id] ?? []);
    return $properties;
}

function woodcrest_get_tenant_meters($tenant_id, $utility_type = 'ELECTRIC') {
    global $woodcrest_db;
    $viewer = woodcrest_fe_effective_tenant_id();
    $active = woodcrest_v23_visible_suite_ids($viewer);
    $history = woodcrest_v24_access_history_table();
    $site_today = esc_sql(current_time('Y-m-d'));
    $active_sql = $active ? "(mr.tenant_user_id=".absint($tenant_id)." AND mr.suite_id IN (".implode(',',array_map('intval',$active)).") AND mr.start_date <= CURDATE() AND (mr.end_date IS NULL OR mr.end_date >= CURDATE()))" : '0=1';
    $history_sql = woodcrest_pm_table_exists($history)
        ? "EXISTS (SELECT 1 FROM {$history} ah WHERE ah.user_id=".absint($viewer)." AND ah.end_date<='{$site_today}' AND ah.suite_id=mr.suite_id AND ah.billing_user_id=mr.tenant_user_id AND mr.start_date<=ah.end_date AND (mr.end_date IS NULL OR mr.end_date>=ah.start_date))"
        : '0=1';
    return $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT mr.responsibility_id,mr.tenant_user_id AS billing_tenant_user_id,mr.allocation_method,mr.fixed_percent,mr.start_date,mr.end_date,
               m.meter_id,m.meter_number,m.meter_name,m.utility_type,m.meter_type,m.meter_size,m.property_id,
               p.property_name,s.suite_id,s.suite_label,s.square_feet
        FROM {$woodcrest_db->meter_responsibility} mr
        JOIN {$woodcrest_db->meter} m ON mr.meter_id=m.meter_id
        LEFT JOIN {$woodcrest_db->property} p ON m.property_id=p.property_id
        LEFT JOIN {$woodcrest_db->suite} s ON mr.suite_id=s.suite_id
        WHERE mr.responsible_party_type='TENANT' AND m.utility_type=%s AND ({$active_sql} OR {$history_sql})
        ORDER BY p.property_name,m.meter_number,mr.start_date DESC
    ", $utility_type));
}

function woodcrest_get_meter_readings($meter_id, $limit = 6, $viewer_id = 0) {
    global $woodcrest_db;
    $viewer_id = $viewer_id ?: (woodcrest_fe_effective_tenant_id());
    $active_ids = woodcrest_v23_visible_suite_ids($viewer_id);
    $archive = woodcrest_v24_access_history_table();
    $active_primary=woodcrest_v23_primary_tenant_id($viewer_id);
    $member_reading_scope = woodcrest_v24_reading_member_scope_sql($viewer_id,'mr.suite_id','so.business_id','COALESCE(r.period_start,r.reading_date)','COALESCE(r.period_end,r.reading_date)');
    $active_sql = $active_ids ? "EXISTS (SELECT 1 FROM {$woodcrest_db->meter_responsibility} mr JOIN {$woodcrest_db->suite_occupancy} so ON so.suite_id=mr.suite_id AND so.tenant_user_id=mr.tenant_user_id WHERE mr.meter_id=r.meter_id AND mr.tenant_user_id=".absint($active_primary)." AND mr.suite_id IN (".implode(',',array_map('intval',$active_ids)).") AND mr.responsible_party_type='TENANT' AND mr.start_date<=CURDATE() AND (mr.end_date IS NULL OR mr.end_date>=CURDATE()) AND so.start_date<=COALESCE(r.period_start,r.reading_date) AND (so.end_date IS NULL OR so.end_date>=COALESCE(r.period_end,r.reading_date)) AND {$member_reading_scope})" : '0=1';
    $site_today = esc_sql(current_time('Y-m-d'));
    $history_sql = woodcrest_pm_table_exists($archive) ? "EXISTS (SELECT 1 FROM {$archive} ah JOIN {$woodcrest_db->meter_responsibility} mr ON mr.suite_id=ah.suite_id AND mr.tenant_user_id=ah.billing_user_id AND mr.meter_id=r.meter_id WHERE ah.user_id=".absint($viewer_id)." AND ah.end_date<='{$site_today}' AND ah.start_date<=COALESCE(r.period_start,r.reading_date) AND ah.end_date>=COALESCE(r.period_end,r.reading_date) AND mr.start_date<=COALESCE(r.period_start,r.reading_date) AND (mr.end_date IS NULL OR mr.end_date>=COALESCE(r.period_end,r.reading_date)))" : '0=1';
    $safe_suites=array_values(array_unique(array_merge($active_ids, woodcrest_v24_historical_suite_ids($viewer_id))));
    $safe_ids=$safe_suites ? implode(',',array_map('intval',$safe_suites)) : '0';
    $no_shared="NOT EXISTS (SELECT 1 FROM {$woodcrest_db->meter_responsibility} other WHERE other.meter_id=r.meter_id AND other.suite_id IS NOT NULL AND other.suite_id NOT IN ({$safe_ids}) AND other.start_date<=COALESCE(r.period_end,r.reading_date) AND (other.end_date IS NULL OR other.end_date>=COALESCE(r.period_start,r.reading_date)))";

    return $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT r.reading_date,r.current_read,r.previous_read,r.usage_amount,r.usage_unit
        FROM {$woodcrest_db->utility_reading} r WHERE r.meter_id=%d AND ({$active_sql} OR {$history_sql}) AND {$no_shared}
        ORDER BY r.reading_date DESC LIMIT %d
    ", absint($meter_id), absint($limit)));
}

function woodcrest_get_selected_meter($meters, $selected_meter_id) {
    if (!$selected_meter_id && !empty($meters)) return $meters[0];
    foreach ($meters as $meter) {
        if ((int)$meter->meter_id === (int)$selected_meter_id) return $meter;
    }
    return !empty($meters) ? $meters[0] : null;
}

function woodcrest_tenant_meter_responsibility_percent($meter, $as_of = '') {
    global $woodcrest_db;
    if (!$meter) return null;

    $method = strtoupper((string)($meter->allocation_method ?? 'ACTUAL'));
    if ($method === 'ACTUAL') return 100.0;
    if ($method === 'FIXED_PERCENT') {
        return $meter->fixed_percent === null ? null : max(0.0, min(100.0, (float)$meter->fixed_percent));
    }
    if ($method !== 'PRO_RATA_SF' || empty($meter->suite_id)) return null;

    $as_of = $as_of ?: current_time('Y-m-d');
    $assigned_sqft = (float)$woodcrest_db->get_var($woodcrest_db->prepare("
        SELECT COALESCE(SUM(x.square_feet), 0)
        FROM (
            SELECT DISTINCT s.suite_id, s.square_feet
            FROM {$woodcrest_db->meter_responsibility} mr
            JOIN {$woodcrest_db->suite} s ON s.suite_id = mr.suite_id
            WHERE mr.meter_id = %d
              AND mr.suite_id IS NOT NULL
              AND mr.start_date <= %s
              AND (mr.end_date IS NULL OR mr.end_date >= %s)
        ) x
    ", (int)$meter->meter_id, $as_of, $as_of));

    $suite_sqft = (float)($meter->square_feet ?? 0);
    if ($assigned_sqft <= 0 || $suite_sqft <= 0) return null;
    return max(0.0, min(100.0, ($suite_sqft / $assigned_sqft) * 100));
}

function woodcrest_tenant_rate_agreement($tenant_id, $utility_type, $as_of = '') {
    global $woodcrest_db;
    $as_of = $as_of ?: current_time('Y-m-d');
    $value = $woodcrest_db->get_var($woodcrest_db->prepare("
        SELECT base_rate_per_unit
        FROM {$woodcrest_db->rate_schedule}
        WHERE tenant_user_id = %d
          AND utility_type = %s
          AND start_date <= %s
          AND (end_date IS NULL OR end_date >= %s)
        ORDER BY start_date DESC, rate_schedule_id DESC
        LIMIT 1
    ", $tenant_id, strtoupper((string)$utility_type), $as_of, $as_of));
    return $value === null ? null : (float)$value;
}

function woodcrest_tenant_usage_unit($utility_type) {
    return strtoupper((string)$utility_type) === 'WATER' ? 'gallons' : 'kWh';
}

function woodcrest_tenant_format_rate($rate, $unit) {
    if ($rate === null) return '—';
    $value = rtrim(rtrim(number_format((float)$rate, 6, '.', ''), '0'), '.');
    if ($value === '') $value = '0';
    $denominator = $unit === 'gallons' ? 'gallon' : 'kWh';
    return $value . ' $/' . $denominator;
}

function woodcrest_tenant_meter_overview_html($tenant_id, $meter, $meters, $input_name) {
    if (!$meter) return '';

    $today = current_time('Y-m-d');
    $as_of = !empty($meter->end_date) && $meter->end_date < $today ? $meter->end_date : $today;
    $unit = woodcrest_tenant_usage_unit($meter->utility_type);
    $pct = woodcrest_tenant_meter_responsibility_percent($meter, $as_of);
    $rate = woodcrest_tenant_rate_agreement((int)($meter->billing_tenant_user_id ?? $tenant_id), $meter->utility_type, $as_of);
    $pct_text = $pct === null ? '—' : rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';
    $meter_label = trim((string)$meter->property_name . ' – ' . (string)$meter->meter_number);
    $has_multiple = count($meters) > 1;

    ob_start(); ?>
    <div class="woodcrest-tenant-meter-overview" aria-label="Meter billing details">
        <div class="woodcrest-tenant-meter-detail woodcrest-tenant-meter-detail-meter">
            <span class="woodcrest-tenant-meter-detail-label">Meter</span>
            <?php if ($has_multiple): ?>
                <form method="get" class="woodcrest-tenant-meter-select-form">
                    
                    <select name="<?php echo esc_attr($input_name); ?>" onchange="this.form.submit()" aria-label="Select meter">
                        <?php foreach ($meters as $m): ?>
                            <option value="<?php echo esc_attr($m->meter_id); ?>" <?php selected((int)$meter->meter_id, (int)$m->meter_id); ?>><?php echo esc_html($m->property_name . ' – ' . $m->meter_number); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php else: ?>
                <span class="woodcrest-tenant-meter-detail-value"><?php echo esc_html($meter_label); ?></span>
            <?php endif; ?>
        </div>
        <div class="woodcrest-tenant-meter-detail">
            <span class="woodcrest-tenant-meter-detail-label">Responsibility</span>
            <span class="woodcrest-tenant-meter-detail-value"><?php echo esc_html($pct_text); ?></span>
        </div>
        <div class="woodcrest-tenant-meter-detail">
            <span class="woodcrest-tenant-meter-detail-label">Rate</span>
            <span class="woodcrest-tenant-meter-detail-value"><?php echo esc_html(woodcrest_tenant_format_rate($rate, $unit)); ?></span>
        </div>
    </div>
    <?php return ob_get_clean();
}

function woodcrest_get_invoice_costs_by_line_type($tenant_id, $line_type, $limit = 2) {
    global $woodcrest_db;
    $viewer = woodcrest_fe_effective_tenant_id();
    $access = woodcrest_v23_suite_access_ready() ? woodcrest_v24_invoice_scope_sql('i',$viewer) : 'i.tenant_user_id = '.absint($viewer);
    return $woodcrest_db->get_results($woodcrest_db->prepare("
        SELECT i.invoice_id, i.invoice_number, i.period_end, SUM(li.amount) AS total_amount
        FROM {$woodcrest_db->invoice} i
        JOIN {$woodcrest_db->invoice_line_item} li ON i.invoice_id = li.invoice_id
        WHERE {$access} AND li.line_type = %s
        GROUP BY i.invoice_id, i.invoice_number, i.period_end
        ORDER BY i.period_end DESC LIMIT %d
    ", $line_type, $limit));
}

function woodcrest_format_money_change($diff) {
    if ($diff === null) return '—';
    return ($diff >= 0 ? '+' : '-') . number_format(abs($diff), 2) . ' $';
}

function woodcrest_change_class($diff, $increase_is_bad = true) {
    if ($diff === null) return 'is-neutral';
    if ($diff > 0) return $increase_is_bad ? 'is-negative' : 'is-positive';
    if ($diff < 0) return $increase_is_bad ? 'is-positive' : 'is-negative';
    return 'is-neutral';
}



function woodcrest_tenant_header_html() {
    $tenant_id = woodcrest_get_tenant_id();
    $account_id = woodcrest_fe_effective_tenant_id();
    $tenant = woodcrest_get_tenant($account_id);
    if (!$tenant) return '<p>Tenant not found.</p>';
    ob_start(); ?>
    <div class="woodcrest-header-box wc-role-tenant">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="woodcrest-header-badge">Tenant</div>
                <h1><?php echo esc_html($tenant->full_name); ?></h1>
                <p><?php echo esc_html($tenant->email); ?></p>
            </div>
            <?php echo woodcrest_fe_header_nav_html('tenant'); ?>
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
    <div class="woodcrest-stats wc-role-tenant">
        <div class="woodcrest-stat-card"><span class="woodcrest-stat-label">Current Balance</span><span class="woodcrest-stat-value"><?php echo number_format($curr_total, 2); ?> $</span></div>
        <div class="woodcrest-stat-card"><span class="woodcrest-stat-label">Previous Month</span><span class="woodcrest-stat-value"><?php echo number_format($prev_total, 2); ?> $</span></div>
        <div class="woodcrest-stat-card"><span class="woodcrest-stat-label">Monthly Change</span><span class="woodcrest-stat-value <?php echo esc_attr(woodcrest_change_class($diff)); ?>"><?php echo esc_html(woodcrest_format_money_change($diff)); ?></span></div>
    </div>
    <?php return ob_get_clean();
}

function woodcrest_tenant_properties_html() {
    $tenant_id = woodcrest_get_tenant_id();
    $properties = woodcrest_get_tenant_properties($tenant_id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-card-wide wc-role-tenant"><div class="woodcrest-card-header"><h2>Properties</h2></div><div class="woodcrest-table-wrap"><table class="woodcrest-table"><thead><tr><th>Property</th><th>Address</th><th>Suite</th><th>Meters</th></tr></thead><tbody>
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
    <section class="woodcrest-card wc-role-tenant"><div class="woodcrest-card-header"><h2>Electric</h2></div>
    <?php if (!empty($meters)): ?>
        <?php if ($selected): ?>
            <?php echo woodcrest_tenant_meter_overview_html($tenant_id, $selected, $meters, 'electric_meter_id'); ?>
            <div class="woodcrest-table-wrap"><table class="woodcrest-table"><thead><tr><th>Date</th><th>Read</th><th>Period Usage</th></tr></thead><tbody>
            <?php if ($readings): foreach ($readings as $r): ?><tr><td><?php echo esc_html($r->reading_date); ?></td><td><?php echo esc_html(number_format((float)$r->current_read, 2)); ?> kWh</td><td><?php echo esc_html(number_format((float)$r->usage_amount, 2)); ?> kWh</td></tr><?php endforeach; else: ?><tr><td colspan="3">No electric readings yet.</td></tr><?php endif; ?>
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
    <section class="woodcrest-card wc-role-tenant"><div class="woodcrest-card-header"><h2>Water</h2></div>
    <?php if (!empty($meters)): ?>
        <?php if ($selected): ?>
            <?php echo woodcrest_tenant_meter_overview_html($tenant_id, $selected, $meters, 'water_meter_id'); ?>
            <div class="woodcrest-table-wrap"><table class="woodcrest-table"><thead><tr><th>Date</th><th>Read</th><th>Period Usage</th></tr></thead><tbody>
            <?php if ($readings): foreach ($readings as $r): ?><tr><td><?php echo esc_html($r->reading_date); ?></td><td><?php echo esc_html(number_format((float)$r->current_read, 2)); ?> gallons</td><td><?php echo esc_html(number_format((float)$r->usage_amount, 2)); ?> gallons</td></tr><?php endforeach; else: ?><tr><td colspan="3">No water readings yet.</td></tr><?php endif; ?>
            </tbody></table></div>
        <?php endif; ?>
    <?php else: ?><p>No water meters.</p><?php endif; ?>
    </section>
    <?php return ob_get_clean();
}

function woodcrest_tenant_invoices_html() {
    if (function_exists('woodcrest_tenant_invoices_v2_html')) {
        return woodcrest_tenant_invoices_v2_html();
    }
    return '<section class="woodcrest-card woodcrest-card-wide wc-role-tenant"><div class="woodcrest-card-header"><h2>Invoices</h2></div><p>Invoice viewer is unavailable.</p></section>';
}

add_shortcode('woodcrest_tenant_header', 'woodcrest_tenant_header_html');
add_shortcode('woodcrest_tenant_summary', 'woodcrest_tenant_summary_html');
add_shortcode('woodcrest_tenant_properties', 'woodcrest_tenant_properties_html');
add_shortcode('woodcrest_tenant_electric', 'woodcrest_tenant_electric_html');
add_shortcode('woodcrest_tenant_water', 'woodcrest_tenant_water_html');
add_shortcode('woodcrest_tenant_invoices', 'woodcrest_tenant_invoices_html');


