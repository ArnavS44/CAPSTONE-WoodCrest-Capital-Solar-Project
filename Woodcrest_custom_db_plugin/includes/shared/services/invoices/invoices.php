<?php
function woodcrest_fe_table_exists($table_name) {
    global $wpdb;
    static $cache = [];
    if (isset($cache[$table_name])) return $cache[$table_name];
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
    return $cache[$table_name] = ($found === $table_name);
}

function woodcrest_fe_invoice_line_has_nnn_column() {
    global $wpdb;
    $t = woodcrest_fe_tables();
    static $has = null;
    if ($has !== null) return $has;
    $column = $wpdb->get_var("SHOW COLUMNS FROM {$t['invoice_line_item']} LIKE 'nnn_allocation_id'");
    return $has = !empty($column);
}

function woodcrest_fe_invoice_period_valid($start, $end) {
    if (!woodcrest_fe_date_is_valid($start) || !woodcrest_fe_date_is_valid($end) || !$start || !$end) return false;
    return $start <= $end;
}

function woodcrest_fe_invoice_get_occupancy($occupancy_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT so.occupancy_id, so.business_id, so.tenant_user_id, so.start_date AS occupancy_start, so.end_date AS occupancy_end,
                s.suite_id, s.suite_label, s.square_feet, s.property_id,
                p.property_name, p.entity_name, p.address_line1, p.city, p.state, p.zip,
                u.display_name, u.user_email
         FROM {$t['suite_occupancy']} so
         JOIN {$t['suite']} s ON s.suite_id = so.suite_id
         JOIN {$t['property']} p ON p.property_id = s.property_id
         JOIN {$t['users']} u ON u.ID = so.tenant_user_id
         WHERE so.occupancy_id = %d",
        $occupancy_id
    ));
}

function woodcrest_fe_invoice_occupancy_allowed($occupancy, $period_start = '', $period_end = '') {
    if (!$occupancy) return false;
    if (!woodcrest_fe_manager_can_manage_record_property((int) $occupancy->property_id)) return false;
    if ($period_start && $period_end) {
        if ($occupancy->occupancy_start > $period_end) return false;
        if (!empty($occupancy->occupancy_end) && $occupancy->occupancy_end < $period_start) return false;
    }
    return true;
}

function woodcrest_fe_invoice_effective_rate($rates, $utility_type, $period_start, $period_end) {
    foreach ($rates as $rate) {
        if ($rate->utility_type !== $utility_type) continue;
        if ($rate->start_date > $period_start) continue;
        if (!empty($rate->end_date) && $rate->end_date < $period_end) continue;
        return (float) $rate->base_rate_per_unit;
    }
    return null;
}


function woodcrest_fe_invoice_period_account_rate($utility_account_id, $start, $end) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    if (empty($t['utility_monthly_cost']) || !woodcrest_fe_table_exists($t['utility_monthly_cost'])) return null;
    if (!woodcrest_fe_invoice_period_valid($start, $end)) return null;

    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT unit_rate FROM {$t['utility_monthly_cost']} WHERE utility_account_id = %d AND period_start <= %s AND period_end >= %s ORDER BY period_start DESC LIMIT 1",
        absint($utility_account_id),
        $start, $end
    ));
    return $value === null ? null : (float)$value;
}

function woodcrest_fe_invoice_find_occupancy_id($property_id, $tenant_user_id, $suite_id, $period_start, $period_end) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $property_id = absint($property_id);
    $tenant_user_id = absint($tenant_user_id);
    $suite_id = absint($suite_id);
    if (!$property_id || !$tenant_user_id || !$suite_id || !woodcrest_fe_invoice_period_valid($period_start, $period_end)) return 0;
    if (!woodcrest_fe_manager_can_manage_record_property($property_id)) return 0;

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT so.occupancy_id
         FROM {$t['suite_occupancy']} so
         JOIN {$t['suite']} s ON s.suite_id = so.suite_id
         WHERE s.property_id = %d
           AND so.tenant_user_id = %d
           AND so.suite_id = %d
           AND so.start_date <= %s
           AND (so.end_date IS NULL OR so.end_date >= %s)
         ORDER BY so.start_date DESC, so.occupancy_id DESC
         LIMIT 1",
        $property_id, $tenant_user_id, $suite_id, $period_end, $period_start
    ));
}

function woodcrest_v25_meter_suite_allocation($meter_id, $suite_id, $start, $end) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT mr.responsibility_id, mr.suite_id, mr.responsible_party_type, mr.allocation_method, mr.fixed_percent,
                mr.start_date, mr.end_date, s.square_feet
         FROM {$t['meter_responsibility']} mr
         LEFT JOIN {$t['suite']} s ON s.suite_id=mr.suite_id
         WHERE mr.meter_id=%d
           AND mr.start_date<=%s AND (mr.end_date IS NULL OR mr.end_date>=%s)
         ORDER BY mr.responsibility_id",
         $meter_id, $end, $start
    ));
    if (!$rows) return new WP_Error('allocation_missing', 'No tenant responsibility is assigned to this meter.');
    $by_suite = [];
    foreach ($rows as $row) {
        if (!$row->suite_id || (isset($row->responsible_party_type) && $row->responsible_party_type !== 'TENANT') || $row->start_date > $start || ($row->end_date && $row->end_date < $end)) {
            return new WP_Error('allocation_dates', 'Meter assignment dates do not fully cover the reading period.');
        }
        $id = (int)$row->suite_id;
        if (isset($by_suite[$id])) return new WP_Error('allocation_duplicate', 'Overlapping meter responsibilities exist for the same suite.');
        $by_suite[$id] = $row;
    }
    if (!isset($by_suite[(int)$suite_id])) return new WP_Error('allocation_suite', 'This suite is not assigned to the meter.');
    if (count($by_suite) === 1) {
        $row = reset($by_suite);
        if ($row->allocation_method === 'FIXED_PERCENT') {
            $percent = (float)$row->fixed_percent;
            if ($percent <= 0 || $percent > 100) return new WP_Error('allocation_percent', 'Set a valid meter allocation percentage.');
            return $percent / 100;
        }
        return 1.0;
    }
    $methods = array_unique(array_map(static fn($r) => $r->allocation_method, array_values($by_suite)));
    if (count($methods) !== 1 || $methods[0] === 'ACTUAL') {
        return new WP_Error('allocation_shared', 'Shared meter requires one consistent Fixed Percent or Pro Rata Square Feet method across suites.');
    }
    if ($methods[0] === 'FIXED_PERCENT') {
        $total = 0.0;
        foreach ($by_suite as $row) {
            $value = (float)$row->fixed_percent;
            if ($value <= 0 || $value > 100) return new WP_Error('allocation_percent', 'Each shared suite needs a valid fixed allocation percentage.');
            $total += $value;
        }
        if (abs($total - 100) > 0.01) return new WP_Error('allocation_total', 'Shared meter fixed allocations must add up to 100%.');
        return (float)$by_suite[(int)$suite_id]->fixed_percent / 100;
    }
    if ($methods[0] === 'PRO_RATA_SF') {
        $total = 0.0;
        foreach ($by_suite as $row) {
            if ((float)$row->square_feet <= 0) return new WP_Error('allocation_area', 'Every shared suite needs a positive floor area for pro rata billing.');
            $total += (float)$row->square_feet;
        }
        return (float)$by_suite[(int)$suite_id]->square_feet / $total;
    }
    return new WP_Error('allocation_method', 'Unknown shared meter allocation method.');
}

function woodcrest_fe_invoice_build_preview($occupancy_id, $period_start, $period_end) {
    global $wpdb;
    $t = woodcrest_fe_tables();

    $result = [
        'ok' => false,
        'can_confirm' => false,
        'error' => '',
        'warnings' => [],
        'occupancy' => null,
        'lines' => [],
        'subtotal' => 0.0,
        'tax_total' => 0.0,
        'total_amount' => 0.0,
        'pro_rata' => null,
        'pro_rata_portion' => null,
        'period_start' => $period_start,
        'period_end' => $period_end,
    ];

    if (!woodcrest_v23_ready()) {
        $result['error'] = 'Apply the Woodcrest v2.3 database migration first.';
        return $result;
    }
    if (!woodcrest_fe_invoice_period_valid($period_start, $period_end)) {
        $result['error'] = 'Choose a valid billing period.';
        return $result;
    }

    $occupancy = woodcrest_fe_invoice_get_occupancy($occupancy_id);
    if (!woodcrest_fe_invoice_occupancy_allowed($occupancy, $period_start, $period_end)) {
        $result['error'] = 'That tenant / suite assignment is not available to you for the selected billing period.';
        return $result;
    }
    $active_start = max($period_start, $occupancy->occupancy_start);
    $active_end = min($period_end, $occupancy->occupancy_end ?: $period_end);
    $result['occupancy'] = $occupancy;

    $duplicate = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT invoice_id FROM {$t['invoice']}
         WHERE tenant_user_id = %d AND suite_id = %d
           AND period_start <= %s AND period_end >= %s
         LIMIT 1",
        $occupancy->tenant_user_id,
        $occupancy->suite_id,
        $period_end,
        $period_start
    ));
    if ($duplicate) {
        $result['error'] = 'An invoice already overlaps this tenant, suite, and billing period.';
        return $result;
    }

    
    $rates = $wpdb->get_results($wpdb->prepare(
        "SELECT rate_schedule_id, utility_type, base_rate_per_unit, start_date, end_date
         FROM {$t['rate_schedule']}
         WHERE tenant_user_id = %d
           AND start_date <= %s
           AND (end_date IS NULL OR end_date >= %s)
         ORDER BY start_date DESC, rate_schedule_id DESC",
        $occupancy->tenant_user_id,
        $period_end,
        $period_start
    ));

    $readings = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT ur.reading_id, ur.reading_date, ur.period_start, ur.period_end, ur.period_source, ur.usage_amount, ur.usage_unit,
                         mr.responsibility_id, mr.allocation_method, mr.fixed_percent,
                         m.meter_id, m.meter_number, m.utility_type, m.utility_account_id, mr.start_date AS responsibility_start, mr.end_date AS responsibility_end,
                         ua.account_number, ua.utility_company
         FROM {$t['meter_responsibility']} mr
         JOIN {$t['meter']} m ON m.meter_id = mr.meter_id
         JOIN {$t['utility_account']} ua ON ua.utility_account_id = m.utility_account_id
         JOIN {$t['utility_reading']} ur ON ur.meter_id = m.meter_id
         WHERE mr.tenant_user_id = %d
           AND mr.suite_id = %d
           AND m.property_id = %d
           AND mr.responsible_party_type = 'TENANT'
           AND ur.period_start <= %s AND ur.period_end >= %s
           AND ur.period_end >= mr.start_date
           AND (mr.end_date IS NULL OR ur.period_start <= mr.end_date)
         ORDER BY ur.reading_date, ur.reading_id",
        $occupancy->tenant_user_id,
        $occupancy->suite_id,
        $occupancy->property_id,
        $active_end,
        $active_start
    ));

    $meter_ids = array_values(array_unique(array_map(static fn($r) => (int)$r->meter_id, (array)$readings)));
    $hierarchy_table = $wpdb->prefix . 'wc_meter_hierarchy';
    if (count($meter_ids) > 1 && woodcrest_fe_table_exists($hierarchy_table)) {
        $meter_placeholders = implode(',', array_fill(0, count($meter_ids), '%d'));
        $hierarchy_args = array_merge($meter_ids, $meter_ids, [$active_end, $active_start]);
        $hierarchy_links = $wpdb->get_results($wpdb->prepare(
            "SELECT upstream_meter_id, downstream_meter_id FROM {$hierarchy_table}
             WHERE upstream_meter_id IN ({$meter_placeholders}) AND downstream_meter_id IN ({$meter_placeholders})
               AND (start_date IS NULL OR start_date<=%s) AND (end_date IS NULL OR end_date>=%s)",
            ...$hierarchy_args
        ));
        if ($hierarchy_links) $result['warnings'][] = 'An upstream master meter and its downstream submeter are both assigned for this period. Adjust meter associations to avoid double billing.';
    }

    $expected_meters = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT mr.meter_id, m.meter_number
         FROM {$t['meter_responsibility']} mr
         JOIN {$t['meter']} m ON m.meter_id = mr.meter_id
         WHERE mr.tenant_user_id = %d AND mr.suite_id = %d AND m.property_id = %d
           AND mr.responsible_party_type = 'TENANT'
           AND mr.start_date <= %s AND (mr.end_date IS NULL OR mr.end_date >= %s)",
        $occupancy->tenant_user_id, $occupancy->suite_id, $occupancy->property_id,
        $active_end, $active_start
    ));
    $reading_meters = [];
    foreach ($readings as $reading) $reading_meters[(int)$reading->meter_id] = true;
    foreach ($expected_meters as $meter) {
        if (empty($reading_meters[(int)$meter->meter_id])) {
            $result['warnings'][] = 'No actual reading is recorded for assigned meter ' . $meter->meter_number . ' in the selected billing period.';
        }
    }

    $utility_groups = [];
    foreach ($readings as $reading) {
        if ($reading->period_start < $active_start || $reading->period_end > $active_end || $reading->responsibility_start > $reading->period_start || (!empty($reading->responsibility_end) && $reading->responsibility_end < $reading->period_end)) {
            $result['warnings'][] = 'Meter ' . $reading->meter_number . ' has a reading period (' . $reading->period_start . ' – ' . $reading->period_end . ') outside the tenant billing dates. Split or correct the meter reading; consumption is not prorated.';
            continue;
        }
        $allocation = woodcrest_v25_meter_suite_allocation((int)$reading->meter_id, (int)$occupancy->suite_id, $reading->period_start, $reading->period_end);
        if (is_wp_error($allocation)) {
            $result['warnings'][] = 'Meter ' . $reading->meter_number . ': ' . $allocation->get_error_message();
            continue;
        }
        $type = $reading->utility_type;
        $partial_rate = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['utility_monthly_cost']} WHERE utility_account_id = %d AND period_start <= %s AND period_end >= %s",
            (int)$reading->utility_account_id, $reading->period_end, $reading->period_start
        ));
        $rate = woodcrest_fe_invoice_period_account_rate((int)$reading->utility_account_id, $reading->period_start, $reading->period_end);
        if ($rate === null && $partial_rate) {
            $result['warnings'][] = 'A provider rate changes within the reading period for meter ' . $reading->meter_number . '. Split the reading at the rate boundary.';
            continue;
        }
        if ($rate === null) {
            $rate = woodcrest_fe_invoice_effective_rate($rates, $type, $reading->period_start, $reading->period_end);
        }
        if ($rate === null) {
            $result['warnings'][] = sprintf(
                'No utility rate covers the complete reading period for %s account %s (meter %s, %s). Split that meter period or update the rate dates.',
                $reading->utility_company ?: 'utility',
                $reading->account_number ?: '—',
                $reading->meter_number,
                $reading->period_start . ' – ' . $reading->period_end
            );
            continue;
        }
        if (!isset($utility_groups[$type])) {
            $utility_groups[$type] = [
                'usage' => 0.0,
                'amount' => 0.0,
                'unit' => $reading->usage_unit,
                'meters' => [],
            ];
        }
        $usage = (float) $reading->usage_amount * $allocation;
        $utility_groups[$type]['usage'] += $usage;
        $utility_groups[$type]['amount'] += $usage * $rate;
        $utility_groups[$type]['meters'][(int)$reading->meter_id] = true;
        if (!$utility_groups[$type]['unit'] && $reading->usage_unit) {
            $utility_groups[$type]['unit'] = $reading->usage_unit;
        }
    }

    foreach ($utility_groups as $type => $group) {
        $usage = round($group['usage'], 4);
        $amount = round($group['amount'], 2);
        $effective_rate = $usage != 0.0 ? round($amount / $usage, 6) : 0.0;
        $meter_count = count($group['meters']);
        $description = ucfirst(strtolower($type)) . ' Usage';
        if ($group['unit']) $description .= ' (' . $group['unit'] . ')';
        if ($meter_count > 1) $description .= ' - ' . $meter_count . ' meters';
        $result['lines'][] = [
            'description' => $description,
            'quantity' => $usage,
            'unit_price' => $effective_rate,
            'amount' => $amount,
            'line_type' => $type === 'WATER' ? 'WATER_USAGE' : 'ELECTRIC_USAGE',
            'nnn_allocation_id' => null,
        ];
    }

    
    $fixed_fees = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT mr.responsibility_id, mr.description, mr.monthly_charge_amount, mr.start_date, mr.end_date, m.meter_number
         FROM {$t['meter_responsibility']} mr
         JOIN {$t['meter']} m ON m.meter_id = mr.meter_id
         WHERE mr.tenant_user_id = %d
           AND mr.suite_id = %d
           AND m.property_id = %d
           AND mr.responsible_party_type = 'TENANT'
           AND mr.monthly_charge_amount IS NOT NULL
           AND mr.monthly_charge_amount <> 0
           AND mr.start_date <= %s
           AND (mr.end_date IS NULL OR mr.end_date >= %s)",
        $occupancy->tenant_user_id,
        $occupancy->suite_id,
        $occupancy->property_id,
        $period_end,
        $period_start
    ));
    foreach ($fixed_fees as $fee) {
        $amount = woodcrest_v23_prorated_monthly($fee->monthly_charge_amount, $period_start, $period_end, max($active_start, $fee->start_date), $fee->end_date ? min($active_end, $fee->end_date) : $active_end);
        if ($amount <= 0) continue;
        $result['lines'][] = [
            'description' => $fee->description ?: ('Monthly meter charge - ' . $fee->meter_number),
            'quantity' => 1.0,
            'unit_price' => $amount,
            'amount' => $amount,
            'line_type' => 'FIXED_FEE',
            'nnn_allocation_id' => null,
        ];
    }

    
    if (woodcrest_fe_table_exists($t['nnn_reconciliation']) && woodcrest_fe_table_exists($t['nnn_expense']) && woodcrest_fe_table_exists($t['nnn_allocation'])) {
        $billing_year = (int) date('Y', strtotime($period_end));
        if (substr($period_start, 0, 4) !== substr($period_end, 0, 4)) {
            $result['warnings'][] = 'Split billing at the calendar-year boundary to apply the correct Triple Net allocation year.';
        }
        $nnn_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.nnn_allocation_id, a.square_feet_used, a.allocation_percentage,
                    a.monthly_amount, e.expense_type
             FROM {$t['nnn_allocation']} a
             JOIN {$t['nnn_expense']} e ON e.nnn_expense_id = a.nnn_expense_id
             JOIN {$t['nnn_reconciliation']} r ON r.nnn_reconciliation_id = e.nnn_reconciliation_id
             WHERE a.occupancy_id = %d
               AND r.billing_year = %d
               AND r.property_id = %d
               AND r.status IN ('DRAFTED','FINALIZED')
               AND e.expense_type IN ('CAM','INSURANCE','PROPERTY_TAX')
             ORDER BY FIELD(e.expense_type, 'CAM','INSURANCE','PROPERTY_TAX')",
            $occupancy->occupancy_id,
            $billing_year,
            $occupancy->property_id
        ));
        $nnn_total = 0.0;
        foreach ($nnn_rows as $nnn) {
            $amount = woodcrest_v23_prorated_monthly($nnn->monthly_amount, $period_start, $period_end, $active_start, $active_end);
            if ($amount <= 0) continue;
            $labels = [
                'CAM' => 'CAM Allocation',
                'INSURANCE' => 'Insurance Allocation',
                'PROPERTY_TAX' => 'Property Tax Allocation',
            ];
            $result['lines'][] = [
                'description' => $labels[$nnn->expense_type] ?? $nnn->expense_type,
                'quantity' => 1.0,
                'unit_price' => $amount,
                'amount' => $amount,
                'line_type' => $nnn->expense_type,
                'nnn_allocation_id' => (int)$nnn->nnn_allocation_id,
            ];
            $nnn_total += $amount;
            if ($result['pro_rata'] === null) {
                $result['pro_rata'] = (float)$nnn->allocation_percentage;
            }
        }
        if ($nnn_rows) $result['pro_rata_portion'] = round($nnn_total, 2);
    }

    foreach ($result['lines'] as $line) {
        $result['subtotal'] += (float)$line['amount'];
    }
    $result['subtotal'] = round($result['subtotal'], 2);
    $result['tax_total'] = 0.0;
    $result['total_amount'] = round($result['subtotal'] + $result['tax_total'], 2);
    if (empty($result['lines'])) {
        $result['warnings'][] = 'No billable utility readings, fixed charges, or drafted NNN allocations were found for this period.';
    }

    $result['warnings'] = array_values(array_unique($result['warnings']));
    $result['ok'] = true;
    $result['can_confirm'] = !empty($result['lines']) && empty($result['warnings']);

    return $result;
}

function woodcrest_fe_invoice_money($amount) {
    return number_format((float)$amount, 2) . ' $';
}

function woodcrest_fe_invoice_status_label($status) {
    $status = strtoupper((string)$status);
    if (in_array($status, ['DRAFTED','CONFIRMED','READY','SENT'], true)) return 'DRAFTED';
    return 'UNDRAFTED';
}

function woodcrest_fe_invoice_line_display($line) {
    $type = strtoupper((string)(is_object($line) ? $line->line_type : ($line['line_type'] ?? '')));
    $qty = (float)(is_object($line) ? $line->quantity : ($line['quantity'] ?? 0));
    $rate = (float)(is_object($line) ? $line->unit_price : ($line['unit_price'] ?? 0));

    if (in_array($type, ['CAM','INSURANCE','PROPERTY_TAX'], true)) {
        return ['qty' => '—', 'rate' => '—'];
    }
    if ($type === 'FIXED_FEE') {
        return ['qty' => '1', 'rate' => woodcrest_fe_invoice_money($rate)];
    }
    $qty_text = rtrim(rtrim(number_format($qty, 4, '.', ','), '0'), '.');
    return ['qty' => $qty_text, 'rate' => woodcrest_fe_invoice_money($rate)];
}

function woodcrest_fe_invoice_preview_html($preview, $show_confirm = true) {
    if (!empty($preview['error'])) {
        return '<div class="woodcrest-fe-notice error">' . esc_html($preview['error']) . '</div>';
    }
    if (empty($preview['occupancy'])) return '';
    $o = $preview['occupancy'];

    ob_start(); ?>
    <section class="woodcrest-card woodcrest-card-wide woodcrest-invoice-preview">
        <div class="woodcrest-card-header"><h2>Estimated Invoice Preview</h2></div>
        <div class="woodcrest-invoice-meta-grid">
            <div><strong>Tenant</strong><br><?php echo esc_html($o->display_name); ?></div>
            <div><strong>Property</strong><br><?php echo esc_html($o->property_name); ?></div>
            <div><strong>Suite</strong><br><?php echo esc_html($o->suite_label); ?></div>
            <div><strong>Square Feet</strong><br><?php echo esc_html(number_format((float)$o->square_feet, 2)); ?></div>
        </div>
        <?php foreach ($preview['warnings'] as $warning): ?>
            <div class="woodcrest-fe-notice error"><?php echo esc_html($warning); ?></div>
        <?php endforeach; ?>
        <div class="woodcrest-table-wrap">
            <table class="woodcrest-table woodcrest-fe-table">
                <thead><tr><th>Description</th><th>Qty</th><th>Rate</th><th>Amount</th></tr></thead>
                <tbody>
                <?php foreach ($preview['lines'] as $line): $display = woodcrest_fe_invoice_line_display($line); ?>
                    <tr>
                        <td><?php echo esc_html($line['description']); ?></td>
                        <td><?php echo esc_html($display['qty']); ?></td>
                        <td><?php echo esc_html($display['rate']); ?></td>
                        <td><?php echo esc_html(woodcrest_fe_invoice_money($line['amount'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th colspan="3">Subtotal</th><th><?php echo esc_html(woodcrest_fe_invoice_money($preview['subtotal'])); ?></th></tr>
                    <tr><th colspan="3">Tax</th><th><?php echo esc_html(woodcrest_fe_invoice_money($preview['tax_total'])); ?></th></tr>
                    <tr><th colspan="3">Total</th><th><?php echo esc_html(woodcrest_fe_invoice_money($preview['total_amount'])); ?></th></tr>
                </tfoot>
            </table>
        </div>
        <?php if ($show_confirm): ?>
            <?php if (!empty($preview['can_confirm'])): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="woodcrest-invoice-confirm-form">
                    <?php echo woodcrest_fe_manager_scope_hidden_input(); ?>
                    <input type="hidden" name="action" value="woodcrest_fe_confirm_invoice">
                    <input type="hidden" name="occupancy_id" value="<?php echo esc_attr($o->occupancy_id); ?>">
                    <input type="hidden" name="period_start" value="<?php echo esc_attr($preview['period_start']); ?>">
                    <input type="hidden" name="period_end" value="<?php echo esc_attr($preview['period_end']); ?>">
                    <?php wp_nonce_field('woodcrest_fe_confirm_invoice', 'woodcrest_invoice_nonce'); ?>
                    <button type="submit" class="woodcrest-fe-button">Save Estimated Invoice</button>
                </form>
            <?php else: ?>
                <div class="woodcrest-invoice-confirm-blocked">
                    <button type="button" class="woodcrest-fe-button woodcrest-fe-button-disabled" disabled>Save Estimated Invoice</button>
                    <p><strong>Estimate cannot be saved yet.</strong> Resolve the warning<?php echo count($preview['warnings']) === 1 ? '' : 's'; ?> shown above, then preview the estimate again.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}

add_action('admin_post_woodcrest_fe_confirm_invoice', 'woodcrest_fe_handle_confirm_invoice');
function woodcrest_fe_handle_confirm_invoice() {
    if (!is_user_logged_in()) auth_redirect();
    if (empty($_POST['woodcrest_invoice_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woodcrest_invoice_nonce'])), 'woodcrest_fe_confirm_invoice')) {
        wp_die('Security check failed.');
    }
    $effective_manager_id = woodcrest_fe_effective_manager_id();
    if (!$effective_manager_id || !woodcrest_fe_is_property_manager($effective_manager_id)) {
        wp_die('This Property Manager action requires an active manager identity.', 403);
    }

    $occupancy_id = absint($_POST['occupancy_id'] ?? 0);
    $period_start = sanitize_text_field(wp_unslash($_POST['period_start'] ?? ''));
    $period_end = sanitize_text_field(wp_unslash($_POST['period_end'] ?? ''));
    $preview = woodcrest_fe_invoice_build_preview($occupancy_id, $period_start, $period_end);
    if (empty($preview['can_confirm'])) {
        $message = $preview['error'] ?: (!empty($preview['warnings']) ? implode(' ', $preview['warnings']) : 'Invoice could not be generated.');
        woodcrest_fe_redirect('error', $message);
    }

    global $wpdb;
    $t = woodcrest_fe_tables();
    $o = $preview['occupancy'];
    $temp_number = 'WC-TMP-' . wp_generate_uuid4();
    $now = current_time('mysql');
    $business_name = !empty($o->business_id) ? $wpdb->get_var($wpdb->prepare(
        "SELECT business_name FROM {$wpdb->prefix}wc_tenant_business WHERE business_id = %d",
        (int)$o->business_id
    )) : '';
    $business_name = $business_name ?: $o->display_name;

    $wpdb->query('START TRANSACTION');
    $ok = $wpdb->insert($t['invoice'], [
        'invoice_number' => $temp_number,
        'property_id' => (int)$o->property_id,
        'suite_id' => (int)$o->suite_id,
        'tenant_user_id' => (int)$o->tenant_user_id,
        'business_id' => (int)$o->business_id ?: null,
        'period_start' => $period_start,
        'period_end' => $period_end,
        'tenant_name_snapshot' => $business_name,
        'attn' => $o->display_name,
        'square_footage_snapshot' => (float)$o->square_feet,
        'pro_rata' => $preview['pro_rata'],
        'pro_rata_portion' => $preview['pro_rata_portion'],
        'subtotal' => $preview['subtotal'],
        'tax_total' => $preview['tax_total'],
        'total_amount' => $preview['total_amount'],
        'status' => 'DRAFTED',
        'created_at' => $now,
    ]);

    if ($ok === false) {
        $wpdb->query('ROLLBACK');
        error_log('Woodcrest invoice header insert failed: ' . $wpdb->last_error);
        woodcrest_fe_redirect('error', 'Invoice header could not be saved.');
    }

    $invoice_id = (int)$wpdb->insert_id;
    $invoice_number = 'WC-' . date('Ym', strtotime($period_end)) . '-' . str_pad((string)$invoice_id, 5, '0', STR_PAD_LEFT);
    if ($wpdb->update($t['invoice'], ['invoice_number' => $invoice_number], ['invoice_id' => $invoice_id]) === false) {
        $wpdb->query('ROLLBACK');
        error_log('Woodcrest invoice number update failed: ' . $wpdb->last_error);
        woodcrest_fe_redirect('error', 'Invoice number could not be saved.');
    }

    $has_nnn = woodcrest_fe_invoice_line_has_nnn_column();
    foreach ($preview['lines'] as $line) {
        $data = [
            'invoice_id' => $invoice_id,
            'description' => $line['description'],
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'amount' => $line['amount'],
            'line_type' => $line['line_type'],
        ];
        if ($has_nnn) $data['nnn_allocation_id'] = $line['nnn_allocation_id'];
        if ($wpdb->insert($t['invoice_line_item'], $data) === false) {
            $wpdb->query('ROLLBACK');
            error_log('Woodcrest invoice line insert failed: ' . $wpdb->last_error);
            woodcrest_fe_redirect('error', 'An invoice line item could not be saved.');
        }
    }

    $wpdb->query('COMMIT');
    woodcrest_fe_redirect('success', 'Estimated invoice ' . $invoice_number . ' saved and marked DRAFTED.', ['wc_view_invoice' => $invoice_id]);
}

function woodcrest_pm_generate_invoice_html() {
    if (!woodcrest_v23_ready()) return '<div class="woodcrest-fe-notice error">Apply the Woodcrest v2.3 database migration before generating invoices.</div>';
    if ($gate = woodcrest_fe_access_message('manager')) return $gate;
    $ctx = woodcrest_fe_context_or_error();
    if (is_string($ctx)) return $ctx;

    $selected_property = absint($_POST['property_id'] ?? 0);
    $selected_tenant = absint($_POST['tenant_user_id'] ?? 0);
    $selected_suite = absint($_POST['suite_id'] ?? 0);
    $period_start = sanitize_text_field(wp_unslash($_POST['period_start'] ?? current_time('Y-m-01')));
    $period_end = sanitize_text_field(wp_unslash($_POST['period_end'] ?? current_time('Y-m-d')));

    
    $invoice_tenant_options = [];
    $invoice_tenant_attrs = [];
    foreach ($ctx['occupancies'] as $o) {
        $tenant_id = (int)$o->tenant_user_id;
        $invoice_tenant_options[$tenant_id] = $o->display_name . ($o->user_email ? ' — ' . $o->user_email : '');
    }
    foreach ($invoice_tenant_options as $tenant_id => $_label) {
        $ids = array_values($ctx['tenant_property_map'][$tenant_id] ?? []);
        $invoice_tenant_attrs[$tenant_id] = ['data-wc-properties' => implode(',', array_map('intval', $ids))];
    }

    $invoice_suite_options = [];
    foreach ($ctx['suites'] as $suite) {
        if (empty($ctx['suite_tenant_map'][(int)$suite->suite_id])) continue;
        $invoice_suite_options[(int)$suite->suite_id] = $suite->suite_label;
    }

    ob_start(); ?>
    <section class="woodcrest-card woodcrest-card-wide woodcrest-invoice-create-card">
        <div class="woodcrest-card-header"><h2>Create Estimated Invoice</h2></div>
        <form method="post" action="<?php echo esc_url(woodcrest_fe_current_url()); ?>" class="woodcrest-fe-form">
            <?php echo woodcrest_fe_manager_scope_hidden_input(); ?>
            <input type="hidden" name="wc_invoice_preview" value="1">
            <?php wp_nonce_field('woodcrest_fe_invoice_preview', 'woodcrest_invoice_preview_nonce'); ?>
            <?php
            echo woodcrest_fe_cascade_field('Property', 'property_id', $selected_property, true, $ctx['property_options'], [
                'data-wc-role' => 'property',
            ], [], 'Search / select property');
            echo woodcrest_fe_cascade_field('Tenant', 'tenant_user_id', $selected_tenant, true, $invoice_tenant_options, [
                'data-wc-role' => 'tenant',
                'data-wc-filter-property' => '1',
                'data-wc-require-property' => '1',
            ], $invoice_tenant_attrs, 'Select property first');
            echo woodcrest_fe_cascade_field('Suite', 'suite_id', $selected_suite, true, $invoice_suite_options, [
                'data-wc-role' => 'suite',
                'data-wc-filter-tenant' => '1',
                'data-wc-require-property' => '1',
                'data-wc-require-tenant' => '1',
            ], $ctx['suite_option_attrs'], 'Select tenant first');
            ?>
            <div class="woodcrest-fe-field"><label>Billing period start</label><input type="date" name="period_start" value="<?php echo esc_attr($period_start); ?>" required></div>
            <div class="woodcrest-fe-field"><label>Billing period end</label><input type="date" name="period_end" value="<?php echo esc_attr($period_end); ?>" required></div>
            <div class="woodcrest-fe-submit"><button type="submit" class="woodcrest-fe-button">Preview Estimate</button></div>
        </form>
    </section>
    <?php
    $html = ob_get_clean();

    if (!empty($_POST['wc_invoice_preview'])) {
        if (empty($_POST['woodcrest_invoice_preview_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woodcrest_invoice_preview_nonce'])), 'woodcrest_fe_invoice_preview')) {
            $html .= '<div class="woodcrest-fe-notice error">Security check failed.</div>';
        } else {
            $occupancy_id = woodcrest_fe_invoice_find_occupancy_id($selected_property, $selected_tenant, $selected_suite, $period_start, $period_end);
            if (!$occupancy_id) {
                $html .= '<div class="woodcrest-fe-notice error">No tenant occupancy matches that property, suite, and billing period.</div>';
            } else {
                $preview = woodcrest_fe_invoice_build_preview($occupancy_id, $period_start, $period_end);
                $html .= woodcrest_fe_invoice_preview_html($preview, true);
            }
        }
    }

    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('wc_pm_generate_invoice', 'woodcrest_pm_generate_invoice_html');

function woodcrest_fe_invoice_get_full($invoice_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT i.*, p.property_name, p.entity_name, p.address_line1, p.city, p.state, p.zip,
                s.suite_label, u.display_name, u.user_email
         FROM {$t['invoice']} i
         JOIN {$t['property']} p ON p.property_id = i.property_id
         JOIN {$t['suite']} s ON s.suite_id = i.suite_id
         JOIN {$t['users']} u ON u.ID = i.tenant_user_id
         WHERE i.invoice_id = %d",
        $invoice_id
    ));
}

function woodcrest_fe_can_view_invoice($invoice) {
    if (!$invoice || !is_user_logged_in()) return false;

    
    $uid = get_current_user_id();
    if (woodcrest_v23_invoice_can_view($invoice, $uid)) return true;

    
    
    $manager_id = woodcrest_fe_effective_manager_id();
    if ($manager_id && woodcrest_fe_is_property_manager($manager_id)) {
        return woodcrest_fe_in_ids($invoice->property_id, woodcrest_fe_manager_property_ids($manager_id));
    }
    return false;
}



function woodcrest_fe_load_dompdf() {
    if (class_exists('\\Dompdf\\Dompdf')) return true;

    $autoloaders = [
        plugin_dir_path(__FILE__) . 'vendor/autoload.php',
        plugin_dir_path(__FILE__) . 'lib/dompdf/autoload.inc.php',
    ];
    foreach ($autoloaders as $autoload) {
        if (file_exists($autoload)) {
            require_once $autoload;
            if (class_exists('\\Dompdf\\Dompdf')) return true;
        }
    }
    return false;
}

function woodcrest_fe_invoice_get_lines($invoice_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t['invoice_line_item']} WHERE invoice_id = %d ORDER BY line_item_id",
        $invoice_id
    ));
}

function woodcrest_fe_invoice_pdf_url($invoice_id) {
    $invoice_id = absint($invoice_id);
    $args = [
        'action' => 'woodcrest_fe_download_invoice_pdf',
        'invoice_id' => $invoice_id,
    ];
    $url = add_query_arg($args, admin_url('admin-post.php'));
    return wp_nonce_url($url, 'woodcrest_fe_download_invoice_pdf_' . $invoice_id, 'wc_pdf_nonce');
}

function woodcrest_fe_invoice_pdf_html($invoice, $lines) {
    $status = woodcrest_fe_invoice_status_label($invoice->status);
    $address = trim($invoice->address_line1 . ', ' . $invoice->city . ', ' . $invoice->state . ' ' . $invoice->zip);
    $pct_text = '';
    if ($invoice->pro_rata !== null) {
        $pct = (float) $invoice->pro_rata;
        if ($pct <= 1) $pct *= 100;
        $pct_text = number_format($pct, 4) . '%';
    }

    ob_start(); ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 34px 38px; }
    body { font-family: DejaVu Sans, Arial, sans-serif; color:#1f2937; font-size:10.5px; line-height:1.35; }
    .top { width:100%; border-bottom:3px solid #991b1b; padding-bottom:12px; margin-bottom:18px; }
    .brand { font-size:23px; font-weight:bold; color:#991b1b; letter-spacing:.5px; }
    .subtitle { font-size:11px; color:#6b7280; }
    .status { float:right; border:1px solid #d1d5db; padding:5px 9px; border-radius:12px; font-weight:bold; }
    .meta { width:100%; margin:0 0 16px 0; border-collapse:collapse; }
    .meta td { width:50%; vertical-align:top; padding:7px 10px 7px 0; }
    .label { text-transform:uppercase; color:#6b7280; font-size:8px; font-weight:bold; letter-spacing:.4px; }
    .value { margin-top:2px; font-size:10.5px; }
    table.lines { width:100%; border-collapse:collapse; margin-top:8px; }
    .lines th { background:#f3f4f6; text-align:left; padding:7px 6px; border-bottom:1px solid #d1d5db; }
    .lines td { padding:7px 6px; border-bottom:1px solid #e5e7eb; }
    .num { text-align:right; white-space:nowrap; }
    .totals { width:45%; margin-left:auto; margin-top:8px; border-collapse:collapse; }
    .totals td { padding:5px 6px; }
    .totals .grand td { border-top:2px solid #374151; font-size:12px; font-weight:bold; }
    .nnn { margin-top:14px; padding:9px 11px; background:#f9fafb; border:1px solid #e5e7eb; }
    .footer { margin-top:24px; padding-top:10px; border-top:1px solid #e5e7eb; color:#6b7280; font-size:8.5px; }
</style>
</head>
<body>
    <div class="top">
        <span class="status"><?php echo esc_html($status); ?></span>
        <div class="brand">WOODCREST</div>
        <div class="subtitle">Estimated Cost Breakdown</div>
    </div>

    <table class="meta">
        <tr>
            <td><div class="label">Estimate</div><div class="value"><strong><?php echo esc_html($invoice->invoice_number); ?></strong></div></td>
            <td><div class="label">Billing Period</div><div class="value"><?php echo esc_html($invoice->period_start . ' – ' . $invoice->period_end); ?></div></td>
        </tr>
        <tr>
            <td><div class="label">Property</div><div class="value"><strong><?php echo esc_html($invoice->property_name); ?></strong><br><?php echo esc_html($address); ?></div></td>
            <td><div class="label">Bill To</div><div class="value"><strong><?php echo esc_html($invoice->tenant_name_snapshot); ?></strong><br><?php echo esc_html($invoice->user_email); ?></div></td>
        </tr>
        <tr>
            <td><div class="label">Suite</div><div class="value"><?php echo esc_html($invoice->suite_label); ?></div></td>
            <td><div class="label">Square Footage</div><div class="value"><?php echo esc_html(number_format((float)$invoice->square_footage_snapshot, 2) . ' sq. ft.'); ?></div></td>
        </tr>
    </table>

    <table class="lines">
        <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $line): $display = woodcrest_fe_invoice_line_display($line); ?>
            <tr>
                <td><?php echo esc_html($line->description); ?></td>
                <td class="num"><?php echo esc_html($display['qty']); ?></td>
                <td class="num"><?php echo esc_html($display['rate']); ?></td>
                <td class="num"><?php echo esc_html(woodcrest_fe_invoice_money($line->amount)); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num"><?php echo esc_html(woodcrest_fe_invoice_money($invoice->subtotal)); ?></td></tr>
        <tr><td>Tax</td><td class="num"><?php echo esc_html(woodcrest_fe_invoice_money($invoice->tax_total)); ?></td></tr>
        <tr class="grand"><td>Total</td><td class="num"><?php echo esc_html(woodcrest_fe_invoice_money($invoice->total_amount)); ?></td></tr>
    </table>

    <?php if ($pct_text !== ''): ?>
        <div class="nnn"><strong>NNN allocation share:</strong> <?php echo esc_html($pct_text); ?>
        <?php if ($invoice->pro_rata_portion !== null): ?> &nbsp;&nbsp; <strong>Monthly NNN:</strong> <?php echo esc_html(woodcrest_fe_invoice_money($invoice->pro_rata_portion)); ?><?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="footer">This document is an estimated cost breakdown generated from Woodcrest property, utility, and allocation data. It is provided for review and is not a payment receipt.</div>
</body>
</html>
    <?php
    return ob_get_clean();
}

function woodcrest_fe_pdf_ascii($text) {
    $text = wp_strip_all_tags((string)$text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) $text = $converted;
    }
    $text = preg_replace('/[^\x20-\x7E]/', '', $text);
    return $text;
}

function woodcrest_fe_pdf_escape($text) {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], woodcrest_fe_pdf_ascii($text));
}


function woodcrest_fe_invoice_native_pdf($invoice, $lines) {
    $ops = [];
    $text = function($x, $y, $size, $value, $bold = false) use (&$ops) {
        $font = $bold ? 'F2' : 'F1';
        $ops[] = "BT /{$font} {$size} Tf {$x} {$y} Td (" . woodcrest_fe_pdf_escape($value) . ") Tj ET";
    };
    $line = function($x1, $y1, $x2, $y2, $width = 1) use (&$ops) {
        $ops[] = "{$width} w {$x1} {$y1} m {$x2} {$y2} l S";
    };

    $text(42, 748, 20, 'WOODCREST', true);
    $text(42, 730, 10, 'Estimated Cost Breakdown');
    $text(430, 748, 10, woodcrest_fe_invoice_status_label($invoice->status), true);
    $line(42, 718, 570, 718, 2);

    $text(42, 696, 8, 'ESTIMATE', true);
    $text(42, 683, 11, $invoice->invoice_number, true);
    $text(315, 696, 8, 'BILLING PERIOD', true);
    $text(315, 683, 10, $invoice->period_start . ' - ' . $invoice->period_end);

    $text(42, 655, 8, 'PROPERTY', true);
    $text(42, 642, 10, $invoice->property_name, true);
    $text(42, 629, 9, $invoice->address_line1 . ', ' . $invoice->city . ', ' . $invoice->state . ' ' . $invoice->zip);
    $text(315, 655, 8, 'BILL TO', true);
    $text(315, 642, 10, $invoice->tenant_name_snapshot, true);
    $text(315, 629, 9, $invoice->user_email);

    $text(42, 601, 8, 'SUITE', true);
    $text(42, 588, 10, $invoice->suite_label);
    $text(315, 601, 8, 'SQUARE FOOTAGE', true);
    $text(315, 588, 10, number_format((float)$invoice->square_footage_snapshot, 2) . ' sq. ft.');

    $y = 552;
    $line(42, $y + 12, 570, $y + 12, 1);
    $text(44, $y, 9, 'Description', true);
    $text(340, $y, 9, 'Qty', true);
    $text(410, $y, 9, 'Rate', true);
    $text(500, $y, 9, 'Amount', true);
    $line(42, $y - 7, 570, $y - 7, 1);
    $y -= 25;

    foreach ($lines as $row) {
        if ($y < 170) break;
        $display = woodcrest_fe_invoice_line_display($row);
        $desc = woodcrest_fe_pdf_ascii($row->description);
        if (strlen($desc) > 48) $desc = substr($desc, 0, 45) . '...';
        $text(44, $y, 9, $desc);
        $text(340, $y, 9, $display['qty']);
        $text(410, $y, 9, $display['rate']);
        $text(500, $y, 9, woodcrest_fe_invoice_money($row->amount));
        $line(42, $y - 7, 570, $y - 7, .4);
        $y -= 22;
    }

    $y -= 6;
    $text(410, $y, 9, 'Subtotal'); $text(500, $y, 9, woodcrest_fe_invoice_money($invoice->subtotal), true); $y -= 18;
    $text(410, $y, 9, 'Tax'); $text(500, $y, 9, woodcrest_fe_invoice_money($invoice->tax_total), true); $y -= 18;
    $line(405, $y + 10, 570, $y + 10, 1);
    $text(410, $y, 11, 'TOTAL', true); $text(500, $y, 11, woodcrest_fe_invoice_money($invoice->total_amount), true);

    if ($invoice->pro_rata !== null) {
        $pct = (float)$invoice->pro_rata;
        if ($pct <= 1) $pct *= 100;
        $y -= 35;
        $text(42, $y, 9, 'NNN allocation share: ' . number_format($pct, 4) . '%', true);
        if ($invoice->pro_rata_portion !== null) {
            $text(315, $y, 9, 'Monthly NNN: ' . woodcrest_fe_invoice_money($invoice->pro_rata_portion), true);
        }
    }

    $text(42, 55, 7, 'Estimated cost breakdown for review. This document is not a payment receipt.');

    $stream = implode("\n", $ops) . "\n";
    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $n = $i + 1;
        $pdf .= "{$n} 0 obj\n{$object}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    return $pdf;
}

add_action('admin_post_woodcrest_fe_download_invoice_pdf', 'woodcrest_fe_download_invoice_pdf');
function woodcrest_fe_download_invoice_pdf() {
    if (!is_user_logged_in()) auth_redirect();

    $invoice_id = absint($_GET['invoice_id'] ?? 0);
    $nonce = sanitize_text_field(wp_unslash($_GET['wc_pdf_nonce'] ?? ''));
    if (!$invoice_id || !wp_verify_nonce($nonce, 'woodcrest_fe_download_invoice_pdf_' . $invoice_id)) {
        wp_die('Invalid or expired PDF download link.', 'PDF Download', ['response' => 403]);
    }

    $invoice = woodcrest_fe_invoice_get_full($invoice_id);
    if (!woodcrest_fe_can_view_invoice($invoice)) {
        wp_die('Estimate not found or access denied.', 'PDF Download', ['response' => 403]);
    }
    $lines = woodcrest_fe_invoice_get_lines($invoice_id);
    $filename = sanitize_file_name(($invoice->invoice_number ?: ('estimate-' . $invoice_id)) . '.pdf');

    if (woodcrest_fe_load_dompdf()) {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        if (method_exists($options, 'setChroot')) {
            $options->setChroot(plugin_dir_path(__FILE__));
        }
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml(woodcrest_fe_invoice_pdf_html($invoice, $lines), 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        $pdf = $dompdf->output();
    } else {
        $pdf = woodcrest_fe_invoice_native_pdf($invoice, $lines);
    }

    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function woodcrest_fe_invoice_detail_html($invoice_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $invoice = woodcrest_fe_invoice_get_full($invoice_id);
    if (!woodcrest_fe_can_view_invoice($invoice)) {
        return '<div class="woodcrest-fe-notice error">Invoice not found or access denied.</div>';
    }
    $lines = woodcrest_fe_invoice_get_lines($invoice_id);
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-card-wide woodcrest-invoice-document">
        <div class="woodcrest-invoice-title-row">
            <div>
                <div class="woodcrest-header-badge">Estimated Invoice</div>
                <h2><?php echo esc_html($invoice->invoice_number); ?></h2>
            </div>
            <div class="woodcrest-invoice-status"><?php echo esc_html(woodcrest_fe_invoice_status_label($invoice->status)); ?></div>
        </div>
        <div class="woodcrest-invoice-meta-grid">
            <div><strong>Billing Period</strong><br><?php echo esc_html($invoice->period_start . ' – ' . $invoice->period_end); ?></div>
            <div><strong>Property</strong><br><?php echo esc_html($invoice->property_name); ?><br><?php echo esc_html($invoice->address_line1 . ', ' . $invoice->city . ', ' . $invoice->state . ' ' . $invoice->zip); ?></div>
            <div><strong>Bill To</strong><br><?php echo esc_html($invoice->tenant_name_snapshot); ?><br><?php echo esc_html($invoice->user_email); ?></div>
            <div><strong>Suite</strong><br><?php echo esc_html($invoice->suite_label); ?><br><?php echo esc_html(number_format((float)$invoice->square_footage_snapshot, 2) . ' sq. ft.'); ?></div>
        </div>
        <div class="woodcrest-table-wrap">
            <table class="woodcrest-table woodcrest-fe-table">
                <thead><tr><th>Description</th><th>Qty</th><th>Rate</th><th>Amount</th></tr></thead>
                <tbody>
                <?php foreach ($lines as $line): $display = woodcrest_fe_invoice_line_display($line); ?>
                    <tr>
                        <td><?php echo esc_html($line->description); ?></td>
                        <td><?php echo esc_html($display['qty']); ?></td>
                        <td><?php echo esc_html($display['rate']); ?></td>
                        <td><?php echo esc_html(woodcrest_fe_invoice_money($line->amount)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th colspan="3">Subtotal</th><th><?php echo esc_html(woodcrest_fe_invoice_money($invoice->subtotal)); ?></th></tr>
                    <tr><th colspan="3">Tax</th><th><?php echo esc_html(woodcrest_fe_invoice_money($invoice->tax_total)); ?></th></tr>
                    <tr><th colspan="3">Total</th><th><?php echo esc_html(woodcrest_fe_invoice_money($invoice->total_amount)); ?></th></tr>
                </tfoot>
            </table>
        </div>
        <div class="woodcrest-invoice-download-row"><a class="woodcrest-fe-button woodcrest-invoice-pdf-link" href="<?php echo esc_url(woodcrest_fe_invoice_pdf_url($invoice_id)); ?>">Download PDF</a></div>
        <?php if ($invoice->pro_rata !== null): ?>
            <div class="woodcrest-invoice-nnn-summary"><strong>NNN allocation share:</strong> <?php
                $pct = (float)$invoice->pro_rata;
                if ($pct <= 1) $pct *= 100;
                echo esc_html(number_format($pct, 4) . '%');
            ?><?php if ($invoice->pro_rata_portion !== null): ?> &nbsp; <strong>Monthly NNN:</strong> <?php echo esc_html(woodcrest_fe_invoice_money($invoice->pro_rata_portion)); ?><?php endif; ?></div>
        <?php endif; ?>
    </section>
    <?php
    return ob_get_clean();
}

function woodcrest_fe_invoice_previous_amounts($invoices) {
    $invoices = (array)$invoices;
    $previous = [];
    $latest = [];
    for ($index = count($invoices) - 1; $index >= 0; $index--) {
        $invoice = $invoices[$index];
        $key = (int)$invoice->tenant_user_id . ':' . (int)$invoice->suite_id;
        $prior = $latest[$key] ?? null;
        if ($prior && $prior->period_end < $invoice->period_start) {
            $previous[(int)$invoice->invoice_id] = (float)$prior->total_amount;
        }
        if (!$prior || $prior->period_end < $invoice->period_end) {
            $latest[$key] = $invoice;
        }
    }
    return $previous;
}

function woodcrest_fe_invoice_comparison_cells($invoice, $previous_amounts) {
    $id = (int)$invoice->invoice_id;
    if (!array_key_exists($id, $previous_amounts)) return ['—', '—'];
    $previous = (float)$previous_amounts[$id];
    $difference = (float)$invoice->total_amount - $previous;
    return [
        esc_html(woodcrest_fe_invoice_money($previous)),
        '<span class="' . esc_attr(woodcrest_change_class($difference)) . '">' . esc_html(woodcrest_format_money_change($difference)) . '</span>',
    ];
}

function woodcrest_pm_invoices_html() {
    if ($gate = woodcrest_fe_access_message('manager')) return $gate;
    global $wpdb;
    $t = woodcrest_fe_tables();
    $property_ids = woodcrest_fe_manager_property_ids();
    if (!$property_ids) return woodcrest_fe_manager_module_wrap('<div class="woodcrest-card">No assigned properties.</div>');
    $ph = woodcrest_fe_placeholders($property_ids);
    $rows_db = $wpdb->get_results($wpdb->prepare(
        "SELECT i.invoice_id, i.invoice_number, i.tenant_user_id, i.suite_id, i.period_start, i.period_end, i.total_amount, i.status,
                p.property_name, s.suite_label, u.display_name
         FROM {$t['invoice']} i
         JOIN {$t['property']} p ON p.property_id = i.property_id
         JOIN {$t['suite']} s ON s.suite_id = i.suite_id
         JOIN {$t['users']} u ON u.ID = i.tenant_user_id
         WHERE i.property_id IN ({$ph})
         ORDER BY i.period_end DESC, i.invoice_id DESC",
        $property_ids
    ));
    $rows = [];
    $invoice_property_labels = [];
    $previous_amounts = woodcrest_fe_invoice_previous_amounts($rows_db);
    foreach ($rows_db as $i) {
        [$previous_bill, $difference] = woodcrest_fe_invoice_comparison_cells($i, $previous_amounts);
        $invoice_property_labels[] = (string)$i->property_name;
        $rows[] = [
            esc_html($i->invoice_number),
            esc_html($i->display_name),
            esc_html($i->property_name . ' — ' . $i->suite_label),
            woodcrest_fe_period_date_cell($i->period_start),
            woodcrest_fe_period_date_cell($i->period_end),
            esc_html(woodcrest_fe_invoice_money($i->total_amount)),
            $previous_bill,
            $difference,
            esc_html(woodcrest_fe_invoice_status_label($i->status)),
            '<a class="woodcrest-fe-link woodcrest-invoice-view-link" data-invoice-id="' . esc_attr((int)$i->invoice_id) . '" href="' . esc_url(add_query_arg('wc_view_invoice', (int)$i->invoice_id, woodcrest_fe_current_url())) . '#wc-invoice-view-target">View</a> &nbsp; <a class="woodcrest-fe-link woodcrest-invoice-pdf-link" href="' . esc_url(woodcrest_fe_invoice_pdf_url((int)$i->invoice_id)) . '">PDF</a>',
        ];
    }
    $html = '<div id="wc-invoice-view-target" class="woodcrest-invoice-view-target">';
    $view_id = absint($_GET['wc_view_invoice'] ?? 0);
    if ($view_id) $html .= woodcrest_fe_invoice_detail_html($view_id);
    $html .= '</div>';
    $html .= '<section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Estimated Invoices</h2></div>';
    $html .= woodcrest_fe_property_grouped_table(['Estimate','Tenant','Property / Suite','Period Start','Period End','Current Bill','Previous Bill','Difference','Status','Action'], $rows, 2, ['property_labels'=>$invoice_property_labels,'date_column'=>4,'status_column'=>8]);
    $html .= '</section>';
    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('wc_pm_invoices', 'woodcrest_pm_invoices_html');

function woodcrest_tenant_invoices_v2_html() {
    if (!is_user_logged_in()) return '<div class="woodcrest-card"><strong>Please sign in.</strong></div>';
    global $wpdb;
    $t = woodcrest_fe_tables();
    $uid = woodcrest_fe_effective_tenant_id();
    $access_sql = woodcrest_v23_suite_access_ready() ? woodcrest_v24_invoice_scope_sql('i',$uid) : 'i.tenant_user_id = '.absint($uid);
    $rows_db = $wpdb->get_results(
        "SELECT i.invoice_id, i.invoice_number, i.tenant_user_id, i.suite_id, i.period_start, i.period_end, i.total_amount, i.status,
                p.property_name, s.suite_label
         FROM {$t['invoice']} i
         JOIN {$t['property']} p ON p.property_id = i.property_id
         JOIN {$t['suite']} s ON s.suite_id = i.suite_id
         WHERE {$access_sql}
         ORDER BY i.period_end DESC, i.invoice_id DESC"
    );
    $rows = [];
    $previous_amounts = woodcrest_fe_invoice_previous_amounts($rows_db);
    foreach ($rows_db as $i) {
        [$previous_bill, $difference] = woodcrest_fe_invoice_comparison_cells($i, $previous_amounts);
        $rows[] = [
            esc_html($i->invoice_number),
            esc_html($i->property_name . ' — ' . $i->suite_label),
            woodcrest_fe_period_date_cell($i->period_start),
            woodcrest_fe_period_date_cell($i->period_end),
            esc_html(woodcrest_fe_invoice_money($i->total_amount)),
            $previous_bill,
            $difference,
            esc_html(woodcrest_fe_invoice_status_label($i->status)),
            '<a class="woodcrest-fe-link woodcrest-invoice-view-link" data-invoice-id="' . esc_attr((int)$i->invoice_id) . '" href="' . esc_url(add_query_arg('wc_view_invoice', (int)$i->invoice_id, woodcrest_fe_current_url())) . '#wc-invoice-view-target">View</a> &nbsp; <a class="woodcrest-fe-link woodcrest-invoice-pdf-link" href="' . esc_url(woodcrest_fe_invoice_pdf_url((int)$i->invoice_id)) . '">PDF</a>',
        ];
    }
    $html = '<div id="wc-invoice-view-target" class="woodcrest-invoice-view-target">';
    $view_id = absint($_GET['wc_view_invoice'] ?? 0);
    if ($view_id) $html .= woodcrest_fe_invoice_detail_html($view_id);
    $html .= '</div>';
    $html .= '<section class="woodcrest-card woodcrest-card-wide wc-role-tenant"><div class="woodcrest-card-header"><h2>My Invoices</h2></div>';
    $html .= woodcrest_fe_table(['Estimate','Property / Suite','Period Start','Period End','Current Bill','Previous Bill','Difference','Status','Action'], $rows);
    $html .= '</section>';
    return '<div class="woodcrest-fe-module wc-role-tenant">' . $html . '</div>';
}
add_shortcode('wc_tenant_invoices', 'woodcrest_tenant_invoices_v2_html');



add_action('wp_ajax_woodcrest_fe_view_invoice', 'woodcrest_fe_ajax_view_invoice');
function woodcrest_fe_ajax_view_invoice() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Please sign in again.'], 401);
    }
    check_ajax_referer('woodcrest_fe_invoice_view', 'nonce');
    $invoice_id = absint($_POST['invoice_id'] ?? 0);
    if (!$invoice_id) {
        wp_send_json_error(['message' => 'Invalid estimate.'], 400);
    }
    $invoice = woodcrest_fe_invoice_get_full($invoice_id);
    if (!woodcrest_fe_can_view_invoice($invoice)) {
        wp_send_json_error(['message' => 'Estimate not found or access denied.'], 403);
    }
    wp_send_json_success(['html' => woodcrest_fe_invoice_detail_html($invoice_id)]);
}
