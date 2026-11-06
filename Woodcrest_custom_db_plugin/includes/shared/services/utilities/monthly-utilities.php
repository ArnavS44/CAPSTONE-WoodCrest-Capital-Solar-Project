<?php
if (!defined('ABSPATH')) exit;

function woodcrest_utility_unit_for_type($utility_type) {
    return strtoupper((string)$utility_type) === 'WATER' ? 'gal' : 'kWh';
}

function woodcrest_utility_rate_label($utility_type) {
    return strtoupper((string)$utility_type) === 'WATER' ? '$ / gallon' : '$ / kWh';
}

function woodcrest_utility_account_record($account_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT ua.*, p.property_name
         FROM {$t['utility_account']} ua
         JOIN {$t['property']} p ON p.property_id = ua.property_id
         WHERE ua.utility_account_id = %d",
        absint($account_id)
    ));
}

function woodcrest_utility_meter_record($meter_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT m.*, p.property_name, ua.account_number, ua.utility_company
         FROM {$t['meter']} m
         JOIN {$t['property']} p ON p.property_id = m.property_id
         JOIN {$t['utility_account']} ua ON ua.utility_account_id = m.utility_account_id
         WHERE m.meter_id = %d",
        absint($meter_id)
    ));
}

function woodcrest_utility_previous_reading($meter_id, $reading_date) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT reading_id, reading_date, current_read
         FROM {$t['utility_reading']}
         WHERE meter_id = %d AND reading_date < %s
         ORDER BY reading_date DESC, reading_id DESC
         LIMIT 1",
        absint($meter_id),
        $reading_date
    ));
}

function woodcrest_utility_existing_month_reading($meter_id, $reading_date) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['utility_reading']}
         WHERE meter_id = %d AND reading_date = %s
         ORDER BY reading_id DESC
         LIMIT 1",
        absint($meter_id),
        $reading_date
    ));
}

function woodcrest_utility_form_start($utility_action) {
    ob_start(); ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="woodcrest-fe-form woodcrest-utility-form">
        <input type="hidden" name="action" value="woodcrest_utility_workflow">
        <input type="hidden" name="woodcrest_utility_action" value="<?php echo esc_attr($utility_action); ?>">
        <?php wp_nonce_field('woodcrest_utility_workflow', 'woodcrest_utility_nonce'); ?>
        <?php echo woodcrest_fe_manager_scope_hidden_input(); ?>
    <?php
    return ob_get_clean();
}

function woodcrest_utility_redirect($status, $message, $extra = []) {
    $redirect = wp_get_referer() ?: home_url('/');
    $redirect = remove_query_arg(['wc_status','wc_message','wc_edit_monthly_cost'], $redirect);
    $args = array_merge(['wc_status' => $status, 'wc_message' => $message], $extra);
    wp_safe_redirect(add_query_arg($args, $redirect));
    exit;
}

function woodcrest_utility_scope_or_die() {
    $manager_id = woodcrest_fe_effective_manager_id();
    if (!$manager_id || !woodcrest_fe_is_property_manager($manager_id)) {
        wp_die('This utility action requires a Property Manager identity with active property assignments.');
    }
    return $manager_id;
}

function woodcrest_utility_save_reading($meter, $period_start, $period_end, $current_read, $previous_override, $manager_id, $acknowledged = false) {
    global $wpdb;
    $t = woodcrest_fe_tables();

    if (!woodcrest_fe_date_is_valid($period_start) || !woodcrest_fe_date_is_valid($period_end) || $period_start > $period_end) {
        return new WP_Error('invalid_dates', 'Choose a valid reading start and end date.');
    }
    $reading_date = $period_end;
    $collision = (int)$wpdb->get_var($wpdb->prepare("SELECT reading_id FROM {$t['utility_reading']} WHERE meter_id = %d AND period_start <= %s AND period_end >= %s AND reading_date <> %s LIMIT 1", (int)$meter->meter_id, $period_end, $period_start, $reading_date));
    if ($collision) return new WP_Error('overlap', 'This meter already has a reading overlapping the selected dates.');

    $prior = woodcrest_utility_previous_reading((int)$meter->meter_id, $reading_date);
    if ($prior && date('Y-m-d', strtotime($prior->reading_date . ' +1 day')) !== $period_start) {
        return new WP_Error('reading_gap', 'The previous reading for meter ' . $meter->meter_number . ' ended ' . $prior->reading_date . '. This reading must begin the following day to avoid misallocating usage. Record a boundary reading first.');
    }
    if ($prior) {
        $previous = (float)$prior->current_read;
    } elseif ($previous_override !== '' && $previous_override !== null) {
        $previous = (float)$previous_override;
    } else {
        return new WP_Error('missing_previous', 'No earlier reading exists for meter ' . $meter->meter_number . '. Enter a previous reading for its first month.');
    }

    $current = (float)$current_read;
    if ($previous < 0 || $current < 0) return new WP_Error('negative_reading', 'Meter readings cannot be negative.');
    if ($current < $previous) {
        return new WP_Error('reading_decreased', 'Current reading cannot be lower than the previous reading for meter ' . $meter->meter_number . '.');
    }

    $existing = woodcrest_utility_existing_month_reading((int)$meter->meter_id, $reading_date);
    if ($existing && (float)$existing->current_read !== (float)$current) {
        $later = (int)$wpdb->get_var($wpdb->prepare("SELECT reading_id FROM {$t['utility_reading']} WHERE meter_id = %d AND reading_date > %s LIMIT 1", (int)$meter->meter_id, $reading_date));
        if ($later) return new WP_Error('reading_history', 'A later reading depends on this meter value. Correct the reading sequence before modifying it.');
    }
    if ($existing && abs((float)$existing->current_read - $current) < 0.0001 && abs((float)$existing->previous_read - $previous) < 0.0001
        && (string)$existing->period_start === (string)$period_start && (string)$existing->period_end === (string)$period_end) {
        return true;
    }
    if (!$acknowledged) {
        $billed = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['invoice']} WHERE property_id=%d AND status='DRAFTED' AND period_start<=%s AND period_end>=%s",
            (int)$meter->property_id, $period_end, $period_start));
        if ($billed) return new WP_Error('billed_period', 'This property already has generated tenant invoices covering these dates. Check the billing acknowledgment before changing readings; existing invoices will not be recalculated.');
    }
    $data = [
        'meter_id' => (int)$meter->meter_id,
        'reading_date' => $reading_date,
        'period_start' => $period_start,
        'period_end' => $period_end,
        'period_source' => 'VERIFIED',
        'current_read' => $current,
        'previous_read' => $previous,
        'usage_amount' => $current - $previous,
        'usage_unit' => woodcrest_utility_unit_for_type($meter->utility_type),
        'locked' => 0,
        'entered_by_user_id' => get_current_user_id(),
    ];

    if ($existing) {
        $old_values = (array)$existing;
        $ok = $wpdb->update($t['utility_reading'], $data, ['reading_id' => (int)$existing->reading_id]);
        if ($ok !== false && $ok > 0 && function_exists('woodcrest_v25_audit')) {
            woodcrest_v25_audit('reading', (int)$existing->reading_id, 'MANUAL_EDIT', $old_values, $data);
        }
    } else {
        $data['created_at'] = current_time('mysql');
        $ok = $wpdb->insert($t['utility_reading'], $data);
        if ($ok !== false && function_exists('woodcrest_v25_audit')) {
            woodcrest_v25_audit('reading', (int)$wpdb->insert_id, 'MANUAL_CREATE', null, $data);
        }
    }

    return $ok === false ? new WP_Error('db_error', 'The meter reading could not be saved.') : true;
}

add_action('admin_post_woodcrest_utility_workflow', 'woodcrest_utility_handle_workflow');
function woodcrest_utility_handle_workflow() {
    if (!is_user_logged_in()) auth_redirect();
    if (empty($_POST['woodcrest_utility_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woodcrest_utility_nonce'])), 'woodcrest_utility_workflow')) {
        wp_die('Security check failed.');
    }

    global $wpdb;
    $t = woodcrest_fe_tables();
    $manager_id = woodcrest_utility_scope_or_die();
    $action = sanitize_key($_POST['woodcrest_utility_action'] ?? '');
    if (!woodcrest_v23_ready()) woodcrest_utility_redirect('error', 'Apply the Woodcrest v2.3 database migration first.');

    if ($action === 'save_monthly_cost') {
        $id = absint($_POST['monthly_cost_id'] ?? 0);
        $account_id = absint($_POST['utility_account_id'] ?? 0);
        $account = woodcrest_utility_account_record($account_id);
        if (!$account || !woodcrest_fe_manager_can_manage_record_property((int)$account->property_id, $manager_id)) {
            woodcrest_utility_redirect('error', 'That utility account is outside this Property Manager scope.');
        }

        if ($id) {
            $existing_property = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT ua.property_id
                 FROM {$t['utility_monthly_cost']} mc
                 JOIN {$t['utility_account']} ua ON ua.utility_account_id = mc.utility_account_id
                 WHERE mc.monthly_cost_id = %d",
                $id
            ));
            if (!$existing_property || !woodcrest_fe_manager_can_manage_record_property($existing_property, $manager_id)) {
                woodcrest_utility_redirect('error', 'That utility period cost is outside this Property Manager scope.');
            }
        }

        $period_start = sanitize_text_field(wp_unslash($_POST['period_start'] ?? ''));
        $period_end = sanitize_text_field(wp_unslash($_POST['period_end'] ?? ''));
        if (!woodcrest_fe_date_is_valid($period_start) || !woodcrest_fe_date_is_valid($period_end) || $period_start > $period_end) woodcrest_utility_redirect('error', 'Choose valid start/end dates.');

        $unit_rate = (float)($_POST['unit_rate'] ?? 0);
        $fixed_charge = (float)($_POST['fixed_charge'] ?? 0);
        $other_charge = (float)($_POST['other_charge'] ?? 0);
        $provider_bill_raw = trim((string)wp_unslash($_POST['provider_bill_total'] ?? ''));
        $provider_bill_total = $provider_bill_raw === '' ? null : (float)$provider_bill_raw;
        if ($unit_rate < 0 || $fixed_charge < 0 || $other_charge < 0 || ($provider_bill_total !== null && $provider_bill_total < 0)) {
            woodcrest_utility_redirect('error', 'Utility cost amounts cannot be negative.');
        }

        $data = [
            'utility_account_id' => $account_id,
            'period_start' => $period_start,
            'period_end' => $period_end,
            'period_source' => 'VERIFIED',
            'unit_rate' => $unit_rate,
            'fixed_charge' => $fixed_charge,
            'other_charge' => $other_charge,
            'provider_bill_total' => $provider_bill_total,
            'notes' => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')) ?: null,
            'created_by_user_id' => get_current_user_id(),
            'updated_at' => current_time('mysql'),
        ];

        $before_cost = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['utility_monthly_cost']} WHERE monthly_cost_id=%d", $id), ARRAY_A) : null;
        if ($id && $before_cost && (float)$before_cost['unit_rate'] !== (float)$unit_rate && empty($_POST['woodcrest_billed_change_ack'])) {
            $billed=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t['invoice']} WHERE property_id=%d AND status='DRAFTED' AND period_start<=%s AND period_end>=%s",
                (int)$account->property_id,$period_end,$period_start));
            if ($billed) woodcrest_utility_redirect('error','Tenant invoices exist for this period. Acknowledge that cost corrections will not regenerate issued invoices.');
        }
        if ($id) {
            $duplicate_id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT monthly_cost_id FROM {$t['utility_monthly_cost']}
                 WHERE utility_account_id = %d AND period_start <= %s AND period_end >= %s AND monthly_cost_id <> %d LIMIT 1",
                $account_id, $period_end, $period_start, $id
            ));
            if ($duplicate_id) woodcrest_utility_redirect('error', 'That utility account already has a cost record overlapping those dates.');
            $ok = $wpdb->update($t['utility_monthly_cost'], $data, ['monthly_cost_id' => $id]);
        } else {
            $existing_id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT monthly_cost_id FROM {$t['utility_monthly_cost']} WHERE utility_account_id = %d AND period_start <= %s AND period_end >= %s LIMIT 1",
                $account_id, $period_end, $period_start
            ));
            if ($existing_id) woodcrest_utility_redirect('error', 'Another utility cost period already overlaps these dates. Edit that record rather than overwriting it.');
            {
                $data['created_at'] = current_time('mysql');
                $ok = $wpdb->insert($t['utility_monthly_cost'], $data);
            }
        }
        if ($ok !== false && function_exists('woodcrest_v25_audit')) {
            woodcrest_v25_audit('cost', $id ?: (int)$wpdb->insert_id, $id ? 'MANUAL_EDIT' : 'MANUAL_CREATE', $before_cost, $data);
        }
        woodcrest_utility_redirect($ok === false ? 'error' : 'success', $ok === false ? 'Utility period cost could not be saved.' : 'Utility period cost saved.');
    }

    if ($action === 'save_meter_reading') {
        $meter_id = absint($_POST['meter_id'] ?? 0);
        $meter = woodcrest_utility_meter_record($meter_id);
        if (!$meter || !woodcrest_fe_manager_can_manage_record_property((int)$meter->property_id, $manager_id)) {
            woodcrest_utility_redirect('error', 'That meter is outside this Property Manager scope.');
        }
        $period_start = sanitize_text_field(wp_unslash($_POST['period_start'] ?? ''));
        $period_end = sanitize_text_field(wp_unslash($_POST['period_end'] ?? ''));
        $current_raw = trim((string)wp_unslash($_POST['current_read'] ?? ''));
        if (!woodcrest_fe_date_is_valid($period_start) || !woodcrest_fe_date_is_valid($period_end) || $period_start > $period_end || $current_raw === '' || !is_numeric($current_raw)) {
            woodcrest_utility_redirect('error', 'Choose valid reading dates and enter the current meter reading.');
        }
        $previous_raw = trim((string)wp_unslash($_POST['previous_read'] ?? ''));
        if ($previous_raw !== '' && !is_numeric($previous_raw)) woodcrest_utility_redirect('error', 'Previous reading must be numeric.');

        $saved = woodcrest_utility_save_reading($meter, $period_start, $period_end, $current_raw, $previous_raw, $manager_id, !empty($_POST['woodcrest_billed_change_ack']));
        if (is_wp_error($saved)) woodcrest_utility_redirect('error', $saved->get_error_message());
        woodcrest_utility_redirect('success', 'Meter reading saved.');
    }

    if ($action === 'save_meter_readings_batch') {
        $property_id = absint($_POST['property_id'] ?? 0);
        if (!$property_id || !woodcrest_fe_manager_can_manage_record_property($property_id, $manager_id)) {
            woodcrest_utility_redirect('error', 'That property is outside this Property Manager scope.');
        }
        $period_start = sanitize_text_field(wp_unslash($_POST['period_start'] ?? ''));
        $period_end = sanitize_text_field(wp_unslash($_POST['period_end'] ?? ''));
        if (!woodcrest_fe_date_is_valid($period_start) || !woodcrest_fe_date_is_valid($period_end) || $period_start > $period_end) woodcrest_utility_redirect('error', 'Choose valid start/end dates.');

        $currents = isset($_POST['current_read']) && is_array($_POST['current_read']) ? $_POST['current_read'] : [];
        $previouses = isset($_POST['previous_read']) && is_array($_POST['previous_read']) ? $_POST['previous_read'] : [];
        $saved_count = 0;
        $errors = [];

        foreach ($currents as $meter_key => $current_value) {
            $meter_id = absint($meter_key);
            $current_raw = trim((string)wp_unslash($current_value));
            if ($current_raw === '') continue;
            if (!is_numeric($current_raw)) {
                $errors[] = 'One or more current readings were not numeric.';
                continue;
            }
            $meter = woodcrest_utility_meter_record($meter_id);
            if (!$meter || (int)$meter->property_id !== $property_id || !woodcrest_fe_manager_can_manage_record_property((int)$meter->property_id, $manager_id)) {
                $errors[] = 'A submitted meter was outside the selected property.';
                continue;
            }
            $previous_raw = isset($previouses[$meter_key]) ? trim((string)wp_unslash($previouses[$meter_key])) : '';
            if ($previous_raw !== '' && !is_numeric($previous_raw)) {
                $errors[] = 'A previous reading was not numeric for meter ' . $meter->meter_number . '.';
                continue;
            }
            $saved = woodcrest_utility_save_reading($meter, $period_start, $period_end, $current_raw, $previous_raw, $manager_id, !empty($_POST['woodcrest_billed_change_ack']));
            if (is_wp_error($saved)) $errors[] = $saved->get_error_message();
            else $saved_count++;
        }

        if ($errors) {
            $message = ($saved_count ? $saved_count . ' reading(s) saved. ' : '') . implode(' ', array_unique($errors));
            woodcrest_utility_redirect('error', $message, [
                'wc_reading_property' => $property_id,
                'wc_reading_start' => $period_start,
                'wc_reading_end' => $period_end,
            ]);
        }
        woodcrest_utility_redirect('success', $saved_count ? $saved_count . ' meter reading(s) saved.' : 'No readings were entered.', [
            'wc_reading_property' => $property_id,
            'wc_reading_start' => $period_start,
            'wc_reading_end' => $period_end,
        ]);
    }

    woodcrest_utility_redirect('error', 'Unknown utility action.');
}

function woodcrest_manager_monthly_utility_costs_html() {
    if (!woodcrest_v23_ready()) return '<div class="woodcrest-fe-notice error">v2.3 database migration required (WordPress Tools → Woodcrest v2.3 Migration).</div>';
    $ctx = woodcrest_fe_context_or_error();
    if (is_string($ctx)) return $ctx;

    global $wpdb;
    $t = woodcrest_fe_tables();
    $property_ids = $ctx['property_ids'];
    $ph = woodcrest_fe_placeholders($property_ids);

    $costs = $wpdb->get_results($wpdb->prepare(
        "SELECT mc.*, ua.property_id, ua.utility_type, ua.account_number, ua.utility_company, p.property_name
         FROM {$t['utility_monthly_cost']} mc
         JOIN {$t['utility_account']} ua ON ua.utility_account_id = mc.utility_account_id
         JOIN {$t['property']} p ON p.property_id = ua.property_id
         WHERE ua.property_id IN ({$ph})
         ORDER BY mc.period_end DESC, p.property_name, ua.utility_company, ua.account_number",
        $property_ids
    ));

    $edit = null;
    $edit_id = absint($_GET['wc_edit_monthly_cost'] ?? 0);
    if ($edit_id) {
        foreach ($costs as $row) {
            if ((int)$row->monthly_cost_id === $edit_id) { $edit = $row; break; }
        }
    }

    $now_month = (int)current_time('n');
    $now_year = (int)current_time('Y');
    $cost_start = $edit ? $edit->period_start : current_time('Y-m-01');
    $cost_end = $edit ? $edit->period_end : current_time('Y-m-d');
    $selected_property = $edit ? (int)$edit->property_id : 0;

    $account_options = [];
    $account_attrs = [];
    foreach ($ctx['accounts'] as $account) {
        $company = trim((string)$account->utility_company) ?: 'Provider';
        $account_options[(int)$account->utility_account_id] = $account->property_name . ' — ' . $company . ' — ' . $account->account_number . ' (' . $account->utility_type . ')';
        $account_attrs[(int)$account->utility_account_id] = [
            'data-wc-property' => (int)$account->property_id,
            'data-wc-utility' => strtoupper((string)$account->utility_type),
        ];
    }

    $form = woodcrest_utility_form_start('save_monthly_cost');
    $form .= '<input type="hidden" name="monthly_cost_id" value="' . esc_attr($edit->monthly_cost_id ?? '') . '">';
    $form .= woodcrest_fe_cascade_field('Property', 'property_filter_id', $selected_property, true, $ctx['property_options'], [
        'data-wc-role' => 'property',
    ], [], 'Select property');
    $form .= woodcrest_fe_cascade_field('Utility account / provider', 'utility_account_id', $edit->utility_account_id ?? '', true, $account_options, [
        'data-wc-role' => 'account',
        'data-wc-require-property' => '1',
    ], $account_attrs, 'Select property first');
    $form .= woodcrest_fe_field('Period start', 'period_start', 'date', $cost_start, true);
    $form .= woodcrest_fe_field('Period end', 'period_end', 'date', $cost_end, true);
    $form .= woodcrest_fe_field('Unit rate', 'unit_rate', 'number', $edit->unit_rate ?? '', true, [], 'step="0.000001" min="0" data-wc-unit-rate');
    $form .= '<div class="woodcrest-fe-field woodcrest-fe-field-note"><label>Rate unit</label><div class="woodcrest-fe-static-value" data-wc-rate-unit>Choose an account to see the rate in $/kWh or $/gallon.</div></div>';
    $form .= woodcrest_fe_field('Fixed provider charge', 'fixed_charge', 'number', $edit->fixed_charge ?? '0.00', false, [], 'step="0.01" min="0"');
    $form .= woodcrest_fe_field('Other provider charges', 'other_charge', 'number', $edit->other_charge ?? '0.00', false, [], 'step="0.01" min="0"');
    $form .= woodcrest_fe_field('Provider bill total', 'provider_bill_total', 'number', $edit->provider_bill_total ?? '', false, [], 'step="0.01" min="0"');
    $form .= woodcrest_fe_field('Notes', 'notes', 'textarea', $edit->notes ?? '');
    $form .= '<div class="woodcrest-fe-field-note woodcrest-utility-note">Tenant usage billing uses the <strong>unit rate</strong> for the reading period. Fixed/other/provider-total fields are retained for provider-bill tracking and are not automatically divided among tenants.</div>';
    $form .= '<label class="woodcrest-fe-field-note"><input type="checkbox" name="woodcrest_billed_change_ack" value="1"> I understand that issued tenant invoices are not recalculated by rate corrections.</label>';
    $form .= woodcrest_fe_form_end($edit ? 'Update utility period cost' : 'Save utility period cost');

    $cost_sources = function_exists('woodcrest_v25_source_labels') ? woodcrest_v25_source_labels('cost',array_map(static fn($r)=>(int)$r->monthly_cost_id,(array)$costs)) : [];
    $rows = [];
    foreach ($costs as $cost) {
        $rows[] = [
            esc_html(ucfirst(strtolower($cost->utility_type))),
            esc_html($cost->property_name),
            esc_html($cost->utility_company ?: '—'),
            esc_html($cost->account_number),
            esc_html($cost_sources[(int)$cost->monthly_cost_id] ?? 'Manual'),
            woodcrest_fe_period_date_cell($cost->period_start, $cost->period_source === 'INFERRED'),
            woodcrest_fe_period_date_cell($cost->period_end),
            esc_html(number_format((float)$cost->unit_rate, 6) . ' $'),
            esc_html(number_format((float)$cost->fixed_charge, 2) . ' $'),
            esc_html(number_format((float)$cost->other_charge, 2) . ' $'),
            $cost->provider_bill_total === null ? '—' : esc_html(number_format((float)$cost->provider_bill_total, 2) . ' $'),
            woodcrest_fe_edit_link('wc_edit_monthly_cost', $cost->monthly_cost_id),
        ];
    }

    $html  = woodcrest_fe_card($edit ? 'Edit Utility Period Cost' : 'Utility Period Cost', $form . woodcrest_v24_inline_csv('utility_costs'), (bool)$edit);
    $html .= '<section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Utility Period Costs</h2></div>';
    $html .= woodcrest_fe_property_grouped_table(['Utility','Property','Provider','Account','Source','Period Start','Period End','Unit Rate','Fixed','Other','Provider Bill','Action'], $rows, 1, ['date_column'=>6,'type_column'=>0]);
    $html .= '</section>';
    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('woodcrest_manager_utility_costs', 'woodcrest_manager_monthly_utility_costs_html');

function woodcrest_manager_meter_readings_html() {
    if (!woodcrest_v23_ready()) return '<div class="woodcrest-fe-notice error">v2.3 database migration required (WordPress Tools → Woodcrest v2.3 Migration).</div>';
    $ctx = woodcrest_fe_context_or_error();
    if (is_string($ctx)) return $ctx;

    global $wpdb;
    $t = woodcrest_fe_tables();
    $manager_id = woodcrest_fe_effective_manager_id();
    $property_ids = $ctx['property_ids'];
    $ph = woodcrest_fe_placeholders($property_ids);

    $batch_property = absint($_GET['wc_reading_property'] ?? 0);
    if (!$batch_property || !woodcrest_fe_in_ids($batch_property, $property_ids)) $batch_property = (int)$property_ids[0];
    $batch_start = sanitize_text_field(wp_unslash($_GET['wc_reading_start'] ?? current_time('Y-m-01')));
    $batch_end = sanitize_text_field(wp_unslash($_GET['wc_reading_end'] ?? current_time('Y-m-d')));
    if (!woodcrest_fe_date_is_valid($batch_start)) $batch_start = current_time('Y-m-01');
    if (!woodcrest_fe_date_is_valid($batch_end) || $batch_end < $batch_start) $batch_end = $batch_start;
    $batch_date = $batch_end;

    $meter_options = [];
    $meter_attrs = [];
    foreach ($ctx['meters'] as $meter) {
        $meter_options[(int)$meter->meter_id] = $meter->property_name . ' — ' . $meter->meter_number . ' (' . $meter->utility_type . ')';
        $meter_attrs[(int)$meter->meter_id] = [
            'data-wc-property' => (int)$meter->property_id,
            'data-wc-utility' => strtoupper((string)$meter->utility_type),
        ];
    }

    $quick = woodcrest_utility_form_start('save_meter_reading');
    $quick .= woodcrest_fe_cascade_field('Property', 'property_filter_id', '', true, $ctx['property_options'], ['data-wc-role' => 'property'], [], 'Select property');
    $quick .= woodcrest_fe_cascade_field('Meter', 'meter_id', '', true, $meter_options, [
        'data-wc-role' => 'meter', 'data-wc-require-property' => '1'
    ], $meter_attrs, 'Select property first');
    $quick .= woodcrest_fe_field('Period start', 'period_start', 'date', current_time('Y-m-01'), true);
    $quick .= woodcrest_fe_field('Period end', 'period_end', 'date', current_time('Y-m-d'), true);
    $quick .= woodcrest_fe_field('Current reading', 'current_read', 'number', '', true, [], 'step="0.0001" min="0" inputmode="decimal"');
    $quick .= woodcrest_fe_field('Previous reading (first reading only)', 'previous_read', 'number', '', false, [], 'step="0.0001" min="0" inputmode="decimal"');
    $quick .= '<div class="woodcrest-fe-field-note woodcrest-utility-note">If the meter already has history, the previous reading is pulled automatically from the latest reading before the selected period. Only use the previous-reading field when entering the meter for the first time.</div>';
    $quick .= '<label class="woodcrest-fe-field-note"><input type="checkbox" name="woodcrest_billed_change_ack" value="1"> I understand that existing tenant invoices are not recalculated by meter corrections.</label>';
    $quick .= woodcrest_fe_form_end('Save reading');

    $load_url = woodcrest_fe_current_url();
    $batch_selector = '<form method="get" action="' . esc_url($load_url) . '" class="woodcrest-fe-form woodcrest-utility-batch-selector">';
    if (current_user_can('manage_options') && $manager_id !== get_current_user_id()) {
        $batch_selector .= '<input type="hidden" name="manager_id" value="' . esc_attr($manager_id) . '">';
    }
    $batch_selector .= woodcrest_fe_field('Property', 'wc_reading_property', 'select', $batch_property, true, $ctx['property_options']);
    $batch_selector .= woodcrest_fe_field('Period start', 'wc_reading_start', 'date', $batch_start, true);
    $batch_selector .= woodcrest_fe_field('Period end', 'wc_reading_end', 'date', $batch_end, true);
    $batch_selector .= '<div class="woodcrest-fe-submit"><button type="submit" class="woodcrest-fe-button">Load meters</button></div></form>';

    $batch_meters = array_values(array_filter($ctx['meters'], static function($meter) use ($batch_property) {
        return (int)$meter->property_id === (int)$batch_property && strtoupper((string)$meter->status) === 'ACTIVE';
    }));

    $batch = woodcrest_utility_form_start('save_meter_readings_batch');
    $batch .= '<input type="hidden" name="property_id" value="' . esc_attr($batch_property) . '">';
    $batch .= '<input type="hidden" name="period_start" value="' . esc_attr($batch_start) . '">';
    $batch .= '<input type="hidden" name="period_end" value="' . esc_attr($batch_end) . '">';
    $batch .= '<div class="woodcrest-table-wrap"><table class="woodcrest-table woodcrest-fe-table woodcrest-meter-entry-table"><thead><tr><th>Utility</th><th>Meter</th><th>Provider / Account</th><th>Source</th><th>Previous Reading</th><th>Current Reading</th><th>Period Usage</th></tr></thead><tbody>';

    if (!$batch_meters) {
        $batch .= '<tr><td colspan="7">No active meters were found for this property.</td></tr>';
    } else {
        foreach ($batch_meters as $meter) {
            $prior = woodcrest_utility_previous_reading((int)$meter->meter_id, $batch_date);
            $existing = woodcrest_utility_existing_month_reading((int)$meter->meter_id, $batch_date);
            $previous = $prior ? (float)$prior->current_read : null;
            $current = $existing ? (float)$existing->current_read : '';
            $usage = $existing ? (float)$existing->usage_amount : '';
            $account = woodcrest_utility_account_record((int)$meter->utility_account_id);
            $provider = $account ? trim((string)$account->utility_company . ' / ' . (string)$account->account_number, ' /') : (string)$meter->account_number;

            $batch .= '<tr data-wc-meter-entry-row data-previous="' . esc_attr($previous === null ? '' : $previous) . '" data-unit="' . esc_attr(woodcrest_utility_unit_for_type($meter->utility_type)) . '">';
            $batch .= '<td>' . esc_html(ucfirst(strtolower($meter->utility_type))) . '</td>';
            $batch .= '<td>' . esc_html($meter->meter_number) . '</td>';
            $batch .= '<td>' . esc_html($provider ?: '—') . '</td>';
            $origin = $existing && function_exists('woodcrest_v25_reading_source') ? woodcrest_v25_reading_source((int)$existing->reading_id) : '—';
            $batch .= '<td>' . esc_html($origin) . '</td>';
            if ($previous === null) {
                $batch .= '<td><input class="wc-meter-reading-input" type="number" step="0.0001" min="0" name="previous_read[' . esc_attr($meter->meter_id) . ']" value="' . esc_attr($existing->previous_read ?? '') . '" placeholder="First reading" aria-label="Previous reading for ' . esc_attr($meter->meter_number) . '"></td>';
            } else {
                $batch .= '<td>' . esc_html(rtrim(rtrim(number_format($previous, 4, '.', ','), '0'), '.')) . '</td>';
            }
            $batch .= '<td><input class="wc-meter-reading-input" data-wc-current-reading type="number" step="0.0001" min="0" name="current_read[' . esc_attr($meter->meter_id) . ']" value="' . esc_attr($current) . '" aria-label="Current reading for ' . esc_attr($meter->meter_number) . '"></td>';
            $batch .= '<td data-wc-usage-preview>' . ($usage === '' ? '—' : esc_html(rtrim(rtrim(number_format($usage, 4, '.', ','), '0'), '.')) . ' ' . esc_html(woodcrest_utility_unit_for_type($meter->utility_type))) . '</td>';
            $batch .= '</tr>';
        }
    }
    $batch .= '</tbody></table></div>';
    if ($batch_meters) {
        $batch .= '<label class="woodcrest-fe-field-note"><input type="checkbox" name="woodcrest_billed_change_ack" value="1"> I understand that existing tenant invoices are not recalculated by meter corrections.</label>';
        $batch .= woodcrest_fe_form_end('Save all entered readings');
    }
    else $batch .= '</form>';

    $readings = $wpdb->get_results($wpdb->prepare(
        "SELECT ur.*, m.meter_number, m.utility_type, m.property_id, p.property_name, ua.account_number, ua.utility_company
         FROM {$t['utility_reading']} ur
         JOIN {$t['meter']} m ON m.meter_id = ur.meter_id
         JOIN {$t['property']} p ON p.property_id = m.property_id
         JOIN {$t['utility_account']} ua ON ua.utility_account_id = m.utility_account_id
         WHERE m.property_id IN ({$ph})
         ORDER BY ur.reading_date DESC, p.property_name, m.meter_number
         LIMIT 200",
        $property_ids
    ));

    $reading_sources = function_exists('woodcrest_v25_source_labels') ? woodcrest_v25_source_labels('reading',array_map(static fn($r)=>(int)$r->reading_id,(array)$readings)) : [];
    $rows = [];
    foreach ($readings as $reading) {
        $rows[] = [
            esc_html(ucfirst(strtolower($reading->utility_type))),
            esc_html($reading->property_name),
            esc_html($reading->meter_number),
            esc_html($reading->utility_company ?: '—'),
            esc_html($reading_sources[(int)$reading->reading_id] ?? 'Manual'),
            woodcrest_fe_period_date_cell($reading->period_start, $reading->period_source === 'INFERRED'),
            woodcrest_fe_period_date_cell($reading->period_end),
            esc_html(rtrim(rtrim(number_format((float)$reading->previous_read, 4, '.', ','), '0'), '.')),
            esc_html(rtrim(rtrim(number_format((float)$reading->current_read, 4, '.', ','), '0'), '.')),
            esc_html(rtrim(rtrim(number_format((float)$reading->usage_amount, 4, '.', ','), '0'), '.') . ' ' . $reading->usage_unit),
        ];
    }

    $html  = woodcrest_fe_card('Quick Meter Reading', $quick . woodcrest_v24_inline_csv('readings'), false);
    $html .= '<section class="woodcrest-card woodcrest-card-wide woodcrest-meter-batch-card"><div class="woodcrest-card-header"><h2>Batch Meter Readings</h2></div>';
    $html .= '<p class="woodcrest-utility-help">Choose a property and billing period, then enter as many meter readings as you have. Blank rows are ignored.</p>';
    $html .= $batch_selector . $batch . '</section>';
    $html .= '<section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Recent Meter Readings</h2></div>';
    $html .= woodcrest_fe_property_grouped_table(['Utility','Property','Meter','Provider','Source','Period Start','Period End','Previous Reading','Current Reading','Period Usage'], $rows, 1, ['date_column'=>6,'type_column'=>0]);
    $html .= '</section>';

    return '<div class="woodcrest-fe-module wc-role-manager-content woodcrest-utility-workflow-module">' . $html . '</div>';
}
add_shortcode('woodcrest_manager_meter_readings', 'woodcrest_manager_meter_readings_html');
