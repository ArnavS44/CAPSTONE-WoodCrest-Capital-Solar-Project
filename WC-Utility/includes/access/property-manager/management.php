<?php
function woodcrest_manager_tenant_accounts_html() {
    $ctx = woodcrest_fe_context_or_error();
    if (is_string($ctx)) return $ctx;

    $tenant_form = woodcrest_fe_form_start('create_tenant');
    $tenant_form .= woodcrest_fe_field('Username', 'user_login', 'text', '', true);
    $tenant_form .= woodcrest_fe_field('Tenant name', 'display_name', 'text', '', true);
    $tenant_form .= woodcrest_fe_field('Email', 'user_email', 'email', '', true);
    $tenant_form .= woodcrest_fe_field('Temporary password', 'user_pass', 'password', '', true, [], 'minlength="8" autocomplete="new-password"');
    $tenant_form .= woodcrest_fe_form_end('Create tenant account');

    $html = woodcrest_fe_card('Create Tenant Account', $tenant_form, empty($ctx['tenant_map']));
    $html .= woodcrest_v23_business_management_html();
    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('woodcrest_manager_tenant_accounts', 'woodcrest_manager_tenant_accounts_html');
add_shortcode('woodcrest_tenant_businesses', function () { return woodcrest_fe_manager_module_wrap(woodcrest_v23_business_management_html()); });
add_shortcode('woodcrest_manager_suite_accounts', function () { return woodcrest_fe_manager_module_wrap(woodcrest_v23_business_management_html()); });


function woodcrest_manager_tenant_suites_html() {
    $ctx = woodcrest_fe_context_or_error();
    if (is_string($ctx)) return $ctx;

    $edit = $ctx['edit_occupancy'];
    $selected_property = $edit ? (int)$edit->property_id : 0;
    $occupancy_form = woodcrest_fe_form_start('save_occupancy');
    $occupancy_form .= '<input type="hidden" name="occupancy_id" value="' . esc_attr($edit->occupancy_id ?? '') . '">';
    $occupancy_form .= woodcrest_fe_cascade_field('Property', 'property_id', $selected_property, true, $ctx['property_options'], [
        'data-wc-role' => 'property',
    ], [], 'Select property');
    
    $occupancy_form .= woodcrest_fe_cascade_field('Tenant', 'tenant_user_id', $edit->tenant_user_id ?? $ctx['new_tenant_id'], true, $ctx['assignment_user_options'], [
        'data-wc-role' => 'tenant',
        'data-wc-filter-property' => '0',
    ], $ctx['tenant_option_attrs'], 'Search / select tenant name');
    $occupancy_form .= woodcrest_fe_cascade_field('Suite', 'suite_id', $edit->suite_id ?? '', true, $ctx['suite_options'], [
        'data-wc-role' => 'suite',
        'data-wc-filter-tenant' => '0',
        'data-wc-require-property' => '1',
    ], $ctx['suite_option_attrs'], 'Select property first');
    $occupancy_form .= woodcrest_fe_field('Start date', 'start_date', 'date', $edit->start_date ?? current_time('Y-m-d'), true);
    $occupancy_form .= woodcrest_fe_field('End date', 'end_date', 'date', $edit->end_date ?? '');
    $occupancy_form .= woodcrest_fe_form_end($edit ? 'Update tenant assignment' : 'Assign tenant to suite');

    $rows = [];
    foreach ($ctx['occupancies'] as $o) {
        $rows[] = [
            esc_html($o->display_name),
            esc_html($o->user_login),
            esc_html($o->user_email),
            esc_html($o->property_name),
            esc_html($o->suite_label),
            esc_html($o->start_date),
            esc_html($o->end_date ?: 'Active'),
            woodcrest_fe_edit_link('wc_edit_occupancy', $o->occupancy_id),
        ];
    }

    $html  = woodcrest_fe_card($edit ? 'Edit Tenant / Suite Assignment' : 'Assign Tenant to Suite', $occupancy_form . woodcrest_v24_inline_csv('occupancies'), (bool) $ctx['new_tenant_id'] || (bool) $edit);
    $html .= '<section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Tenants / Suite Occupancy</h2></div>';
    $html .= woodcrest_fe_property_grouped_table(['Tenant Name','Username','Email','Property','Suite','Start','End','Action'], $rows, 3, ['date_column'=>5]);
    $html .= '</section>';
    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('woodcrest_manager_tenant_suites', 'woodcrest_manager_tenant_suites_html');


function woodcrest_manager_utility_rates_html() {
    
    
    
    
    
    global $post;
    if ($post instanceof WP_Post && has_shortcode($post->post_content, 'woodcrest_manager_usage_table')) {
        return '';
    }
    return woodcrest_manager_monthly_utility_costs_html();
}
add_shortcode('woodcrest_manager_utility_rates', 'woodcrest_manager_utility_rates_html');


function woodcrest_manager_suites_html() {
    $ctx = woodcrest_fe_context_or_error();
    if (is_string($ctx)) return $ctx;

    $edit = $ctx['edit_suite'];
    $suite_form = woodcrest_fe_form_start('save_suite');
    $suite_form .= '<input type="hidden" name="suite_id" value="' . esc_attr($edit->suite_id ?? '') . '">';
    $suite_form .= woodcrest_fe_field('Property', 'property_id', 'select', $edit->property_id ?? '', true, $ctx['property_options']);
    $suite_form .= woodcrest_fe_field('Suite label', 'suite_label', 'text', $edit->suite_label ?? '', true);
    $suite_form .= woodcrest_fe_field('Square feet', 'square_feet', 'number', $edit->square_feet ?? '', true, [], 'step="0.01" min="0"');
    $suite_form .= '<div class="woodcrest-fe-field woodcrest-fe-field-note"><label>Occupancy status</label><div class="woodcrest-fe-static-value">Calculated automatically from active tenant assignments.</div></div>';
    $suite_form .= woodcrest_fe_form_end($edit ? 'Update suite' : 'Create suite');

    $rows = [];
    foreach ($ctx['suites'] as $s) {
        $rows[] = [
            esc_html($s->property_name),
            esc_html($s->suite_label),
            esc_html(number_format((float) $s->square_feet, 2)),
            esc_html($s->occupancy_status ?? $s->status),
            woodcrest_fe_edit_link('wc_edit_suite', $s->suite_id),
        ];
    }

    $html  = woodcrest_fe_card($edit ? 'Edit Suite' : 'Manage Suites', $suite_form . woodcrest_v24_inline_csv('suites'), (bool) $edit);
    $html .= '<section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Suites</h2></div>';
    $html .= woodcrest_fe_property_grouped_table(['Property','Suite','Square Feet','Status','Action'], $rows, 0);
    $html .= '</section>';
    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('woodcrest_manager_suites', 'woodcrest_manager_suites_html');


function woodcrest_manager_utility_accounts_html() {
    $ctx = woodcrest_fe_context_or_error();
    if (is_string($ctx)) return $ctx;

    $edit = $ctx['edit_account'];
    $form = woodcrest_fe_form_start('save_utility_account');
    $form .= '<input type="hidden" name="utility_account_id" value="' . esc_attr($edit->utility_account_id ?? '') . '">';
    $form .= woodcrest_fe_field('Property', 'property_id', 'select', $edit->property_id ?? '', true, $ctx['property_options']);
    $form .= woodcrest_fe_field('Utility type', 'utility_type', 'select', $edit->utility_type ?? 'ELECTRIC', true, ['ELECTRIC'=>'Electric','WATER'=>'Water']);
    $form .= woodcrest_fe_field('Account number', 'account_number', 'text', $edit->account_number ?? '', true);
    $form .= woodcrest_fe_field('Utility company', 'utility_company', 'text', $edit->utility_company ?? '', true);
    $form .= '<details class="wc-v251-advanced"><summary>Additional account details</summary>';
    $form .= woodcrest_fe_field('ESI ID', 'esi_id', 'text', $edit->esi_id ?? '');
    $form .= woodcrest_fe_field('Service address', 'service_address', 'text', $edit->service_address ?? '');
    $form .= woodcrest_fe_field('Description', 'account_description', 'text', $edit->account_description ?? '');
    $form .= woodcrest_fe_field('Status', 'status', 'select', $edit->status ?? 'ACTIVE', true, ['ACTIVE'=>'Active','INACTIVE'=>'Inactive']);
    $form .= '</details>';
    $form .= woodcrest_fe_form_end($edit ? 'Update utility account' : 'Create utility account');

    $rows = [];
    foreach ($ctx['accounts'] as $a) {
        $rows[] = [
            esc_html($a->property_name),
            esc_html($a->account_number),
            esc_html($a->utility_type),
            esc_html($a->utility_company),
            esc_html($a->status),
            woodcrest_fe_edit_link('wc_edit_account', $a->utility_account_id),
        ];
    }

    $html  = woodcrest_fe_card($edit ? 'Edit Utility Account' : 'Manage Utility Accounts', $form . woodcrest_v24_inline_csv('utility_accounts'), (bool) $edit);
    $html .= '<section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Utility Accounts</h2></div>';
    $html .= woodcrest_fe_property_grouped_table(['Property','Account','Utility','Company','Status','Action'], $rows, 0, ['type_column'=>2]);
    $html .= '</section>';
    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('woodcrest_manager_utility_accounts', 'woodcrest_manager_utility_accounts_html');


function woodcrest_manager_meters_html() {
    $ctx = woodcrest_fe_context_or_error();
    if (is_string($ctx)) return $ctx;

    $edit = $ctx['edit_meter'];
    $form = woodcrest_fe_form_start('save_meter');
    $form .= '<input type="hidden" name="meter_id" value="' . esc_attr($edit->meter_id ?? '') . '">';
    $form .= woodcrest_fe_cascade_field('Property', 'property_id', $edit->property_id ?? '', true, $ctx['property_options'], [
        'data-wc-role' => 'property',
    ], [], 'Select property');
    $form .= woodcrest_fe_cascade_field('Utility account', 'utility_account_id', $edit->utility_account_id ?? '', true, $ctx['account_options'], [
        'data-wc-role' => 'account',
        'data-wc-require-property' => '1',
    ], $ctx['account_option_attrs'], 'Select property first');
    $form .= woodcrest_fe_field('Meter number', 'meter_number', 'text', $edit->meter_number ?? '', true);
    $form .= woodcrest_fe_field('Meter type', 'meter_type', 'select', $edit->meter_type ?? 'SUBMETER', true, ['MASTER'=>'Master','SUBMETER'=>'Submeter']);

    $form .= '<details class="wc-v251-advanced"><summary>Additional meter details</summary>';
    $form .= woodcrest_fe_field('Meter size', 'meter_size', 'text', $edit->meter_size ?? '');
    $form .= woodcrest_fe_field('Meter name', 'meter_name', 'text', $edit->meter_name ?? '');
    $form .= woodcrest_fe_field('Serial number', 'serial_number', 'text', $edit->serial_number ?? '');
    $form .= woodcrest_fe_field('Status', 'status', 'select', $edit->status ?? 'ACTIVE', true, ['ACTIVE'=>'Active','INACTIVE'=>'Inactive']);
    $form .= '</details>';
    $form .= woodcrest_fe_form_end($edit ? 'Update meter' : 'Create meter');

    $rows = [];
    foreach ($ctx['meters'] as $m) {
        $rows[] = [
            esc_html($m->property_name),
            esc_html($m->meter_number),
            esc_html($m->account_number),
            esc_html($m->meter_type),
            esc_html($m->utility_type),
            esc_html($m->status),
            woodcrest_fe_edit_link('wc_edit_meter', $m->meter_id),
        ];
    }

    $html  = woodcrest_fe_card($edit ? 'Edit Meter' : 'Manage Utility Meters', $form . woodcrest_v24_inline_csv('meters'), (bool) $edit);
    $html .= '<section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Meters</h2></div>';
    $html .= woodcrest_fe_property_grouped_table(['Property','Meter','Account','Type','Utility','Status','Action'], $rows, 0, ['type_column'=>4]);
    $html .= '</section>';
    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('woodcrest_manager_meters', 'woodcrest_manager_meters_html');


function woodcrest_manager_meter_assignments_html() {
    $ctx = woodcrest_fe_context_or_error();
    if (is_string($ctx)) return $ctx;

    $edit = $ctx['edit_resp'];
    $selected_property = $edit ? (int)$edit->property_id : 0;
    $form = woodcrest_fe_form_start('save_meter_responsibility');
    $form .= '<input type="hidden" name="responsibility_id" value="' . esc_attr($edit->responsibility_id ?? '') . '">';
    $form .= woodcrest_fe_cascade_field('Property', 'property_id', $selected_property, true, $ctx['property_options'], [
        'data-wc-role' => 'property',
    ], [], 'Select property');
    $form .= woodcrest_fe_cascade_field('Meter', 'meter_id', $edit->meter_id ?? '', true, $ctx['meter_options'], [
        'data-wc-role' => 'meter',
        'data-wc-require-property' => '1',
    ], $ctx['meter_option_attrs'], 'Select property first');
    $form .= woodcrest_fe_field('Assignment', 'responsible_party_type', 'select', $edit->responsible_party_type ?? 'TENANT', true,
        ['TENANT'=>'Tenant suite', 'CAM'=>'Property / CAM', 'VACANT_WOODCREST'=>'Property / Owner'], 'data-wc-assignment-party');
    $form .= '<div class="wc-v251-suite-fields">';
    $form .= woodcrest_fe_cascade_field('Suite', 'suite_id', $edit->suite_id ?? '', false, $ctx['suite_options'], [
        'data-wc-role' => 'suite',
        'data-wc-require-property' => '1',
    ], $ctx['suite_option_attrs'], 'Select property first');
    $form .= woodcrest_fe_field('Allocation method', 'allocation_method', 'select', $edit->allocation_method ?? 'ACTUAL', true,
        ['ACTUAL'=>'Dedicated / full usage', 'PRO_RATA_SF'=>'Shared / square footage', 'FIXED_PERCENT'=>'Shared / fixed percent'], 'data-wc-assignment-method');
    $form .= '<div class="wc-v251-percent-fields">';
    $form .= woodcrest_fe_field('Fixed percent', 'fixed_percent', 'number', $edit->fixed_percent ?? '', false, [], 'min="0" max="100" step="0.01"');
    $form .= '</div></div>';
    $form .= '<details class="wc-v251-advanced"><summary>Advanced billing & dates</summary>';
    $form .= woodcrest_fe_field('Description', 'description', 'text', $edit->description ?? '');
    $form .= woodcrest_fe_field('Billing model', 'billing_model', 'select', $edit->billing_model ?? 'PASS_THROUGH', true, ['PASS_THROUGH'=>'Pass through','RESALE'=>'Resale']);
    $form .= woodcrest_fe_field('Monthly recurring fee (optional)', 'monthly_charge_amount', 'number', $edit->monthly_charge_amount ?? '', false, [], 'step="0.01" min="0"');
    $form .= woodcrest_fe_field('Effective start', 'start_date', 'date', $edit->start_date ?? current_time('Y-m-d'), true);
    $form .= woodcrest_fe_field('Effective end', 'end_date', 'date', $edit->end_date ?? '');
    $form .= '</details>';
    $form .= woodcrest_fe_form_end($edit ? 'Update meter assignment' : 'Associate meter with suite');

    $rows = [];
    foreach ($ctx['responsibilities'] as $r) {
        $rows[] = [
            esc_html($r->property_name),
            esc_html($r->meter_number),
            esc_html($r->suite_label ?: '—'),
            esc_html($r->display_name ?: '—'),
            esc_html($r->responsible_party_type),
            esc_html($r->allocation_method),
            woodcrest_fe_edit_link('wc_edit_responsibility', $r->responsibility_id),
        ];
    }

    $html  = woodcrest_fe_card($edit ? 'Edit Meter / Suite Association' : 'Associate Meter with Suite', $form . woodcrest_v24_inline_csv('meter_responsibilities'), (bool) $edit);
    $html .= '<section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Meter / Suite Associations</h2></div>';
    $html .= woodcrest_fe_property_grouped_table(['Property','Meter','Suite','Tenant','Party','Allocation','Action'], $rows, 0);
    $html .= '</section>';
    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('woodcrest_manager_meter_assignments', 'woodcrest_manager_meter_assignments_html');




function woodcrest_property_admin_management_html() {
    if ($gate = woodcrest_fe_access_message('admin')) return $gate;

    return '<div class="woodcrest-page woodcrest-fe-page">' .
        woodcrest_admin_header_html() .
        woodcrest_admin_properties_html() .
        woodcrest_admin_property_managers_html() .
        '</div>';
}
add_shortcode('woodcrest_property_admin_management', 'woodcrest_property_admin_management_html');


function woodcrest_property_manager_management_html() {
    if ($gate = woodcrest_fe_access_message('manager')) return $gate;

    return '<div class="woodcrest-page woodcrest-manager-page woodcrest-fe-page">' .
        woodcrest_fe_pm_admin_header_html() .
        woodcrest_manager_assigned_properties_html() .
        woodcrest_manager_tenant_accounts_html() .
        woodcrest_manager_tenant_suites_html() .
        woodcrest_manager_monthly_utility_costs_html() .
        woodcrest_manager_meter_readings_html() .
        woodcrest_manager_suites_html() .
        woodcrest_manager_utility_accounts_html() .
        woodcrest_manager_meters_html() .
        woodcrest_manager_meter_assignments_html() .
        '</div>';
}
add_shortcode('woodcrest_property_manager_management', 'woodcrest_property_manager_management_html');




