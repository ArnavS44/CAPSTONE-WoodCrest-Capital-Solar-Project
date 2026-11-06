<?php
function woodcrest_fe_manager_context() {
    static $context = null;
    if ($context !== null) return $context;

    $context = ['error' => ''];

    if ($gate = woodcrest_fe_access_message('manager')) {
        $context['error'] = $gate;
        return $context;
    }

    global $wpdb;
    $t = woodcrest_fe_tables();
    $effective_manager_id = woodcrest_fe_effective_manager_id();
    $property_ids = woodcrest_fe_manager_property_ids($effective_manager_id);

    if (!$property_ids) {
        $context['error'] = '<div class="woodcrest-card"><strong>No assigned properties.</strong></div>';
        return $context;
    }

    $ph = woodcrest_fe_placeholders($property_ids);

    $properties = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t['property']} WHERE property_id IN ({$ph}) ORDER BY property_name",
        $property_ids
    ));

    $suites = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, p.property_name,
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM {$t['suite_occupancy']} so_active
                    WHERE so_active.suite_id = s.suite_id
                      AND so_active.start_date <= CURDATE()
                      AND (so_active.end_date IS NULL OR so_active.end_date >= CURDATE())
                ) THEN 'OCCUPIED' ELSE 'VACANT' END AS occupancy_status
         FROM {$t['suite']} s
         JOIN {$t['property']} p ON p.property_id=s.property_id
         WHERE s.property_id IN ({$ph})
         ORDER BY p.property_name, s.suite_label",
        $property_ids
    ));

    $occupancies = $wpdb->get_results($wpdb->prepare(
        "SELECT so.*, u.user_login, u.display_name, u.user_email, s.suite_label, s.property_id, p.property_name
         FROM {$t['suite_occupancy']} so
         JOIN {$t['users']} u ON u.ID=so.tenant_user_id
         JOIN {$t['suite']} s ON s.suite_id=so.suite_id
         JOIN {$t['property']} p ON p.property_id=s.property_id
         WHERE s.property_id IN ({$ph})
         ORDER BY p.property_name, s.suite_label, so.start_date DESC",
        $property_ids
    ));

    $tenant_map = [];
    foreach ($occupancies as $o) {
        $tenant_map[$o->tenant_user_id] = trim((string)$o->display_name) ?: (string)$o->user_login;
    }

    
    $new_tenant_id = absint($_GET['wc_new_tenant_id'] ?? 0);
    if ($new_tenant_id && (int) get_user_meta($new_tenant_id, '_woodcrest_created_by_manager', true) === (int) $effective_manager_id) {
        $u = get_userdata($new_tenant_id);
        if ($u) $tenant_map[$u->ID] = trim((string)$u->display_name) ?: (string)$u->user_login;
    }

    $created_ids = get_users([
        'meta_key' => '_woodcrest_created_by_manager',
        'meta_value' => $effective_manager_id,
        'fields' => 'ID',
    ]);
    foreach ($created_ids as $uid) {
        $u = get_userdata($uid);
        if ($u) $tenant_map[$u->ID] = trim((string)$u->display_name) ?: (string)$u->user_login;
    }
    ksort($tenant_map);

    $assignment_user_options = [];
    foreach ($tenant_map as $tenant_id => $tenant_name) {
        $assignment_user_options[(int) $tenant_id] = (string) $tenant_name;
    }

    $tenant_ids = array_map('intval', array_keys($tenant_map));
    $rates = [];
    if ($tenant_ids) {
        $tph = woodcrest_fe_placeholders($tenant_ids);
        $rates = $wpdb->get_results($wpdb->prepare(
            "SELECT rs.*, u.display_name
             FROM {$t['rate_schedule']} rs
             JOIN {$t['users']} u ON u.ID=rs.tenant_user_id
             WHERE rs.tenant_user_id IN ({$tph})
             ORDER BY u.display_name, rs.utility_type, rs.start_date DESC",
            $tenant_ids
        ));
    }

    $accounts = $wpdb->get_results($wpdb->prepare(
        "SELECT ua.*, p.property_name
         FROM {$t['utility_account']} ua
         JOIN {$t['property']} p ON p.property_id=ua.property_id
         WHERE ua.property_id IN ({$ph})
         ORDER BY p.property_name, ua.account_number",
        $property_ids
    ));

    $meters = $wpdb->get_results($wpdb->prepare(
        "SELECT m.*, p.property_name, ua.account_number
         FROM {$t['meter']} m
         JOIN {$t['property']} p ON p.property_id=m.property_id
         JOIN {$t['utility_account']} ua ON ua.utility_account_id=m.utility_account_id
         WHERE m.property_id IN ({$ph})
         ORDER BY p.property_name, m.meter_number",
        $property_ids
    ));

    $responsibilities = $wpdb->get_results($wpdb->prepare(
        "SELECT mr.*, m.meter_number, m.property_id, p.property_name, ua.account_number, s.suite_label, u.display_name
         FROM {$t['meter_responsibility']} mr
         JOIN {$t['meter']} m ON m.meter_id=mr.meter_id
         JOIN {$t['property']} p ON p.property_id=m.property_id
         JOIN {$t['utility_account']} ua ON ua.utility_account_id=mr.utility_account_id
         LEFT JOIN {$t['suite']} s ON s.suite_id=mr.suite_id
         LEFT JOIN {$t['users']} u ON u.ID=mr.tenant_user_id
         WHERE m.property_id IN ({$ph})
         ORDER BY p.property_name, m.meter_number",
        $property_ids
    ));

    $property_options = [];
    foreach ($properties as $p) $property_options[$p->property_id] = $p->property_name;

    $suite_options = [];
    foreach ($suites as $s) $suite_options[$s->suite_id] = $s->property_name . ' — ' . $s->suite_label;

    $account_options = [];
    foreach ($accounts as $a) {
        $account_options[$a->utility_account_id] = $a->property_name . ' — ' . $a->account_number . ' (' . $a->utility_type . ')';
    }

    $meter_options = [];
    foreach ($meters as $m) {
        $meter_options[$m->meter_id] = $m->property_name . ' — ' . $m->meter_number . ' (' . $m->utility_type . ')';
    }

    
    $tenant_property_map = [];
    $suite_tenant_map = [];
    foreach ($occupancies as $o) {
        $tenant_id = (int) $o->tenant_user_id;
        $property_id = (int) $o->property_id;
        $suite_id = (int) $o->suite_id;
        $tenant_property_map[$tenant_id][$property_id] = $property_id;
        $suite_tenant_map[$suite_id][$tenant_id] = $tenant_id;
    }

    $tenant_option_attrs = [];
    foreach ($tenant_map as $tenant_id => $_label) {
        $ids = array_values($tenant_property_map[(int)$tenant_id] ?? []);
        $tenant_option_attrs[(int)$tenant_id] = [
            'data-wc-properties' => implode(',', array_map('intval', $ids)),
        ];
    }

    $suite_option_attrs = [];
    foreach ($suites as $suite) {
        $tenant_ids_for_suite = array_values($suite_tenant_map[(int)$suite->suite_id] ?? []);
        $suite_option_attrs[(int)$suite->suite_id] = [
            'data-wc-property' => (int)$suite->property_id,
            'data-wc-tenants' => implode(',', array_map('intval', $tenant_ids_for_suite)),
        ];
    }

    $account_option_attrs = [];
    foreach ($accounts as $account) {
        $account_option_attrs[(int)$account->utility_account_id] = ['data-wc-property' => (int)$account->property_id];
    }

    $meter_option_attrs = [];
    foreach ($meters as $meter) {
        $meter_option_attrs[(int)$meter->meter_id] = [
            'data-wc-property' => (int)$meter->property_id,
            'data-wc-account' => (int)$meter->utility_account_id,
        ];
    }

    $edit_suite = woodcrest_fe_get_record($t['suite'], 'suite_id', 'wc_edit_suite', $property_ids);

    $edit_occupancy = null;
    $occ_id = absint($_GET['wc_edit_occupancy'] ?? 0);
    if ($occ_id) {
        foreach ($occupancies as $o) {
            if ((int) $o->occupancy_id === $occ_id) {
                $edit_occupancy = $o;
                break;
            }
        }
    }

    $edit_rate = null;
    $rate_id = absint($_GET['wc_edit_rate'] ?? 0);
    if ($rate_id) {
        foreach ($rates as $r) {
            if ((int) $r->rate_schedule_id === $rate_id) {
                $edit_rate = $r;
                break;
            }
        }
    }

    $edit_account = woodcrest_fe_get_record($t['utility_account'], 'utility_account_id', 'wc_edit_account', $property_ids);
    $edit_meter = woodcrest_fe_get_record($t['meter'], 'meter_id', 'wc_edit_meter', $property_ids);

    $edit_resp = null;
    $resp_id = absint($_GET['wc_edit_responsibility'] ?? 0);
    if ($resp_id) {
        foreach ($responsibilities as $r) {
            if ((int) $r->responsibility_id === $resp_id) {
                $edit_resp = $r;
                break;
            }
        }
    }

    $context = compact(
        't',
        'property_ids',
        'properties',
        'suites',
        'occupancies',
        'tenant_map',
        'assignment_user_options',
        'new_tenant_id',
        'rates',
        'accounts',
        'meters',
        'responsibilities',
        'property_options',
        'suite_options',
        'account_options',
        'meter_options',
        'tenant_property_map',
        'suite_tenant_map',
        'tenant_option_attrs',
        'suite_option_attrs',
        'account_option_attrs',
        'meter_option_attrs',
        'edit_suite',
        'edit_occupancy',
        'edit_rate',
        'edit_account',
        'edit_meter',
        'edit_resp'
    );
    $context['error'] = '';

    return $context;
}

function woodcrest_fe_context_or_error() {
    $ctx = woodcrest_fe_manager_context();
    return !empty($ctx['error']) ? $ctx['error'] : $ctx;
}




function woodcrest_manager_assigned_properties_html() {
    if (!is_user_logged_in()) {
        return '<div class="woodcrest-card"><strong>Please log in to view assigned properties.</strong></div>';
    }

    global $wpdb;
    $t = woodcrest_fe_tables();

    
    
    
    $manager_id = woodcrest_fe_effective_manager_id();

    if (!$manager_id || !woodcrest_fe_is_property_manager($manager_id)) {
        return woodcrest_fe_manager_module_wrap('<div class="woodcrest-card"><strong>No property-manager assignments were found for this account.</strong></div>');
    }

    $today = current_time('Y-m-d');
    $properties = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT p.*
         FROM {$t['property']} p
         INNER JOIN {$t['user_property_access']} upa ON upa.property_id = p.property_id
         WHERE upa.user_id = %d
           AND upa.access_scope = 'PROPERTY_MANAGER'
           AND (upa.start_date IS NULL OR upa.start_date <= %s)
           AND (upa.end_date IS NULL OR upa.end_date > %s)
         ORDER BY p.property_name",
        $manager_id, $today, $today
    ));

    $counts = $properties ? $wpdb->get_results($wpdb->prepare(
        "SELECT s.property_id, COUNT(*) AS total_suites, SUM(s.square_feet) AS total_area,
                SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM {$t['suite_occupancy']} so
                    WHERE so.suite_id=s.suite_id AND so.start_date<=%s
                      AND (so.end_date IS NULL OR so.end_date>=%s)
                ) THEN 0 ELSE 1 END) AS vacant_suites
         FROM {$t['suite']} s
         WHERE s.property_id IN (" . woodcrest_fe_placeholders(array_map('intval', array_column($properties, 'property_id'))) . ")
         GROUP BY s.property_id", array_merge([$today, $today], array_map('intval', array_column($properties, 'property_id')))), OBJECT_K) : [];
    $html = '<section class="woodcrest-card woodcrest-card-wide wc-assigned-properties-card"><div class="woodcrest-card-header"><h2>My Assigned Properties</h2></div>';
    if ($properties) {
        $html .= '<div class="woodcrest-table-wrap wc-assigned-properties"><table class="woodcrest-table woodcrest-fe-table wc-assigned-property-table">'
            . '<thead><tr><th scope="col">Property</th><th scope="col">Status</th><th scope="col">Suites</th></tr></thead><tbody>';
        foreach ($properties as $p) {
            $property_id = (int)$p->property_id;
            $city_state = trim((string) $p->city . ', ' . (string) $p->state . ' ' . (string) $p->zip);
            $parts = array_filter([(string)($p->address_line1 ?? ''), (string)($p->address_line2 ?? ''), $city_state]);
            $address = implode(', ', $parts);
            $count = $counts[$property_id] ?? null;
            $total = (int)($count->total_suites ?? 0);
            $vacant = (int)($count->vacant_suites ?? 0);
            $occupied = max(0, $total - $vacant);
            $area = (float)($count->total_area ?? 0);
            $toggle_id = 'wc-assigned-property-' . $property_id;
            $html .= '<tr class="wc-assigned-property-row">'
                . '<td><button type="button" class="wc-assigned-property-toggle" aria-expanded="false" aria-controls="'.esc_attr($toggle_id).'" aria-label="Property details for '.esc_attr($p->property_name).'">'
                . '<span class="wc-assigned-property-arrow" aria-hidden="true">&#9656;</span><span>'.esc_html($p->property_name).'</span></button></td>'
                . '<td>'.esc_html($p->status ?: '—').'</td>'
                . '<td>'.esc_html((string)$total).' '.($total===1?'suite':'suites').'</td></tr>';
            $html .= '<tr class="wc-assigned-property-detail" id="'.esc_attr($toggle_id).'" hidden><td colspan="3">'
                . '<div class="wc-assigned-property-subtable"><div class="woodcrest-table-wrap">'
                . '<table class="woodcrest-table wc-assigned-property-info"><thead><tr><th scope="col">Address</th><th scope="col">Entity</th><th scope="col">Total Suites</th><th scope="col">Occupied</th><th scope="col">Vacant</th><th scope="col">Suite Floor Area</th><th scope="col">Time Zone</th></tr></thead><tbody><tr>'
                . '<td>'.esc_html($address ?: '—').'</td>'
                . '<td>'.esc_html($p->entity_name ?: '—').'</td>'
                . '<td>'.esc_html((string)$total).'</td>'
                . '<td>'.esc_html((string)$occupied).'</td>'
                . '<td>'.esc_html((string)$vacant).'</td>'
                . '<td>'.esc_html(number_format($area,0)).' sq ft</td>'
                . '<td>'.esc_html($p->timezone ?: '—').'</td>'
                . '</tr></tbody></table></div></div></td></tr>'
                . '<tr class="wc-assigned-property-spacer" aria-hidden="true"><td colspan="3"></td></tr>';
        }
        $html .= '</tbody></table></div>';
    } else {
        $html .= '<p>No assigned properties.</p>';
    }
    $html .= '</section>';
    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('woodcrest_manager_assigned_properties', 'woodcrest_manager_assigned_properties_html');


