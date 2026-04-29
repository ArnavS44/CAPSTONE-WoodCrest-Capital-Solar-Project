<?php
if (!defined('ABSPATH')) exit;



add_action('admin_menu', function () {
    add_menu_page(
        'Woodcrest Power DB',
        'Woodcrest Power DB',
        'manage_options',
        'woodcrest-power',
        'woodcrest_power_dashboard_page',
        'dashicons-chart-area',
        25
    );

    add_submenu_page('woodcrest-power', 'Manage Access', 'Manage Access', 'manage_options', 'woodcrest-power-users', 'woodcrest_power_users_page');
    add_submenu_page('woodcrest-power', 'Properties', 'Properties', 'manage_options', 'woodcrest-power-properties', 'woodcrest_power_properties_page');
    add_submenu_page('woodcrest-power', 'Meters', 'Meters', 'manage_options', 'woodcrest-power-meters', 'woodcrest_power_meters_page');
    add_submenu_page('woodcrest-power', 'Billing', 'Billing', 'manage_options', 'woodcrest-power-billing', 'woodcrest_power_billing_page');
});

add_action('admin_init', 'woodcrest_power_handle_admin_forms');

function woodcrest_power_handle_admin_forms() {
    if (!current_user_can('manage_options') || empty($_POST['woodcrest_action'])) {
        return;
    }

    check_admin_referer('woodcrest_power_admin_action', 'woodcrest_nonce');

    global $woodcrest_db;
    $action = sanitize_text_field(wp_unslash($_POST['woodcrest_action']));

    switch ($action) {
        case 'assign_property_access':
            $id = absint($_POST['access_id'] ?? 0);
            $data = [
                'user_id' => absint($_POST['user_id'] ?? 0),
                'property_id' => absint($_POST['property_id'] ?? 0),
                'access_scope' => woodcrest_power_enum($_POST['access_scope'] ?? '', ['PROPERTY_MANAGER', 'OWNER'], 'PROPERTY_MANAGER'),
                'start_date' => woodcrest_power_nullable_date($_POST['start_date'] ?? ''),
                'end_date' => woodcrest_power_nullable_date($_POST['end_date'] ?? ''),
            ];
            if (!$id) {
                $data['created_at'] = current_time('mysql');
                $woodcrest_db->insert($woodcrest_db->user_property_access, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->user_property_access, $data, ['access_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-users');
            break;

        case 'assign_suite_occupancy':
            $id = absint($_POST['occupancy_id'] ?? 0);
            $data = [
                'suite_id' => absint($_POST['suite_id'] ?? 0),
                'tenant_user_id' => absint($_POST['tenant_user_id'] ?? 0),
                'start_date' => sanitize_text_field(wp_unslash($_POST['start_date'] ?? '')),
                'end_date' => woodcrest_power_nullable_date($_POST['end_date'] ?? ''),
            ];
            if (!$id) {
                $data['created_at'] = current_time('mysql');
                $woodcrest_db->insert($woodcrest_db->suite_occupancy, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->suite_occupancy, $data, ['occupancy_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-users');
            break;

        case 'save_property':
            $id = absint($_POST['property_id'] ?? 0);
            $data = [
                'entity_name' => sanitize_text_field(wp_unslash($_POST['entity_name'] ?? '')),
                'property_name' => sanitize_text_field(wp_unslash($_POST['property_name'] ?? '')),
                'address_line1' => sanitize_text_field(wp_unslash($_POST['address_line1'] ?? '')),
                'city' => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
                'state' => sanitize_text_field(wp_unslash($_POST['state'] ?? '')),
                'zip' => sanitize_text_field(wp_unslash($_POST['zip'] ?? '')),
                'timezone' => sanitize_text_field(wp_unslash($_POST['timezone'] ?? 'America/Chicago')),
                'status' => woodcrest_power_enum($_POST['status'] ?? '', ['ACTIVE', 'INACTIVE'], 'ACTIVE'),
            ];
            if (!$id) {
                $data['created_at'] = current_time('mysql');
                $woodcrest_db->insert($woodcrest_db->property, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->property, $data, ['property_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-properties');
            break;

        case 'save_suite':
            $id = absint($_POST['suite_id'] ?? 0);
            $data = [
                'property_id' => absint($_POST['property_id'] ?? 0),
                'suite_label' => sanitize_text_field(wp_unslash($_POST['suite_label'] ?? '')),
                'square_feet' => floatval($_POST['square_feet'] ?? 0),
                'status' => woodcrest_power_enum($_POST['status'] ?? '', ['OCCUPIED', 'VACANT'], 'VACANT'),
            ];
            if (!$id) {
                $data['created_at'] = current_time('mysql');
                $woodcrest_db->insert($woodcrest_db->suite, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->suite, $data, ['suite_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-properties');
            break;

        case 'save_utility_account':
            $id = absint($_POST['utility_account_id'] ?? 0);
            $data = [
                'property_id' => absint($_POST['property_id'] ?? 0),
                'utility_type' => woodcrest_power_enum($_POST['utility_type'] ?? '', ['ELECTRIC', 'WATER'], 'ELECTRIC'),
                'account_number' => sanitize_text_field(wp_unslash($_POST['account_number'] ?? '')),
                'esi_id' => woodcrest_power_nullable_text($_POST['esi_id'] ?? ''),
                'utility_company' => sanitize_text_field(wp_unslash($_POST['utility_company'] ?? '')),
                'service_address' => woodcrest_power_nullable_text($_POST['service_address'] ?? ''),
                'account_description' => woodcrest_power_nullable_text($_POST['account_description'] ?? ''),
                'status' => woodcrest_power_enum($_POST['status'] ?? '', ['ACTIVE', 'INACTIVE'], 'ACTIVE'),
            ];
            if (!$id) {
                $data['created_at'] = current_time('mysql');
                $woodcrest_db->insert($woodcrest_db->utility_account, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->utility_account, $data, ['utility_account_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-meters');
            break;

        case 'save_meter':
            $id = absint($_POST['meter_id'] ?? 0);
            $data = [
                'property_id' => absint($_POST['property_id'] ?? 0),
                'utility_account_id' => absint($_POST['utility_account_id'] ?? 0),
                'meter_number' => sanitize_text_field(wp_unslash($_POST['meter_number'] ?? '')),
                'meter_type' => woodcrest_power_enum($_POST['meter_type'] ?? '', ['MASTER', 'SUBMETER'], 'SUBMETER'),
                'utility_type' => woodcrest_power_enum($_POST['utility_type'] ?? '', ['ELECTRIC', 'WATER'], 'ELECTRIC'),
                'meter_size' => woodcrest_power_nullable_text($_POST['meter_size'] ?? ''),
                'meter_name' => woodcrest_power_nullable_text($_POST['meter_name'] ?? ''),
                'serial_number' => woodcrest_power_nullable_text($_POST['serial_number'] ?? ''),
                'status' => woodcrest_power_enum($_POST['status'] ?? '', ['ACTIVE', 'INACTIVE'], 'ACTIVE'),
            ];
            if (!$id) {
                $data['created_at'] = current_time('mysql');
                $woodcrest_db->insert($woodcrest_db->meter, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->meter, $data, ['meter_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-meters');
            break;

        case 'assign_meter_responsibility':
            $id = absint($_POST['responsibility_id'] ?? 0);
            $data = [
                'meter_id' => absint($_POST['meter_id'] ?? 0),
                'utility_account_id' => absint($_POST['utility_account_id'] ?? 0),
                'tenant_user_id' => woodcrest_power_nullable_absint($_POST['tenant_user_id'] ?? ''),
                'suite_id' => woodcrest_power_nullable_absint($_POST['suite_id'] ?? ''),
                'description' => woodcrest_power_nullable_text($_POST['description'] ?? ''),
                'responsible_party_type' => woodcrest_power_enum($_POST['responsible_party_type'] ?? '', ['TENANT', 'VACANT_WOODCREST', 'CAM'], 'TENANT'),
                'allocation_method' => woodcrest_power_enum($_POST['allocation_method'] ?? '', ['ACTUAL', 'PRO_RATA_SF', 'FIXED_PERCENT'], 'ACTUAL'),
                'fixed_percent' => woodcrest_power_nullable_decimal($_POST['fixed_percent'] ?? ''),
                'billing_model' => woodcrest_power_enum($_POST['billing_model'] ?? '', ['PASS_THROUGH', 'RESALE'], 'PASS_THROUGH'),
                'monthly_charge_amount' => woodcrest_power_nullable_decimal($_POST['monthly_charge_amount'] ?? ''),
                'start_date' => sanitize_text_field(wp_unslash($_POST['start_date'] ?? '')),
                'end_date' => woodcrest_power_nullable_date($_POST['end_date'] ?? ''),
            ];
            if (!$id) {
                $data['created_at'] = current_time('mysql');
                $woodcrest_db->insert($woodcrest_db->meter_responsibility, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->meter_responsibility, $data, ['responsibility_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-meters');
            break;

        case 'save_reading':
            $id = absint($_POST['reading_id'] ?? 0);
            $current = floatval($_POST['current_read'] ?? 0);
            $previous = floatval($_POST['previous_read'] ?? 0);
            $data = [
                'meter_id' => absint($_POST['meter_id'] ?? 0),
                'reading_date' => sanitize_text_field(wp_unslash($_POST['reading_date'] ?? '')),
                'current_read' => $current,
                'previous_read' => $previous,
                'usage_amount' => $current - $previous,
                'usage_unit' => sanitize_text_field(wp_unslash($_POST['usage_unit'] ?? 'kWh')),
                'locked' => isset($_POST['locked']) ? 1 : 0,
                'entered_by_user_id' => woodcrest_power_nullable_absint($_POST['entered_by_user_id'] ?? ''),
            ];
            if (!$id) {
                $data['created_at'] = current_time('mysql');
                $woodcrest_db->insert($woodcrest_db->utility_reading, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->utility_reading, $data, ['reading_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-billing');
            break;

        case 'save_master_bill':
            $id = absint($_POST['bill_id'] ?? 0);
            $data = [
                'property_id' => absint($_POST['property_id'] ?? 0),
                'utility_account_id' => absint($_POST['utility_account_id'] ?? 0),
                'master_meter_id' => absint($_POST['master_meter_id'] ?? 0),
                'period_start' => sanitize_text_field(wp_unslash($_POST['period_start'] ?? '')),
                'period_end' => sanitize_text_field(wp_unslash($_POST['period_end'] ?? '')),
                'total_amount' => floatval($_POST['total_amount'] ?? 0),
                'status' => woodcrest_power_enum($_POST['status'] ?? '', ['DRAFT', 'CONFIRMED', 'LOCKED'], 'DRAFT'),
                'created_by_user_id' => absint($_POST['created_by_user_id'] ?? 0),
            ];
            if (!$id) {
                $data['created_at'] = current_time('mysql');
                $woodcrest_db->insert($woodcrest_db->master_utility_bill, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->master_utility_bill, $data, ['bill_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-billing');
            break;

        case 'save_invoice':
            $id = absint($_POST['invoice_id'] ?? 0);
            $data = [
                'invoice_number' => sanitize_text_field(wp_unslash($_POST['invoice_number'] ?? '')),
                'property_id' => absint($_POST['property_id'] ?? 0),
                'suite_id' => absint($_POST['suite_id'] ?? 0),
                'tenant_user_id' => absint($_POST['tenant_user_id'] ?? 0),
                'period_start' => sanitize_text_field(wp_unslash($_POST['period_start'] ?? '')),
                'period_end' => sanitize_text_field(wp_unslash($_POST['period_end'] ?? '')),
                'tenant_name_snapshot' => sanitize_text_field(wp_unslash($_POST['tenant_name_snapshot'] ?? '')),
                'attn' => woodcrest_power_nullable_text($_POST['attn'] ?? ''),
                'square_footage_snapshot' => woodcrest_power_nullable_decimal($_POST['square_footage_snapshot'] ?? ''),
                'pro_rata' => woodcrest_power_nullable_decimal($_POST['pro_rata'] ?? ''),
                'pro_rata_portion' => woodcrest_power_nullable_decimal($_POST['pro_rata_portion'] ?? ''),
                'subtotal' => floatval($_POST['subtotal'] ?? 0),
                'tax_total' => floatval($_POST['tax_total'] ?? 0),
                'total_amount' => floatval($_POST['total_amount'] ?? 0),
                'status' => woodcrest_power_enum($_POST['status'] ?? '', ['DRAFT', 'CONFIRMED', 'VOIDED', 'SENT'], 'DRAFT'),
                'confirmed_at' => woodcrest_power_nullable_datetime($_POST['confirmed_at'] ?? ''),
                'confirmed_by_user_id' => woodcrest_power_nullable_absint($_POST['confirmed_by_user_id'] ?? ''),
            ];
            if (!$id) {
                $data['created_at'] = current_time('mysql');
                $woodcrest_db->insert($woodcrest_db->invoice, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->invoice, $data, ['invoice_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-billing');
            break;

        case 'save_invoice_line_item':
            $id = absint($_POST['line_item_id'] ?? 0);
            $data = [
                'invoice_id' => absint($_POST['invoice_id'] ?? 0),
                'description' => sanitize_text_field(wp_unslash($_POST['description'] ?? '')),
                'quantity' => floatval($_POST['quantity'] ?? 0),
                'unit_price' => floatval($_POST['unit_price'] ?? 0),
                'amount' => floatval($_POST['amount'] ?? 0),
                'line_type' => woodcrest_power_enum($_POST['line_type'] ?? '', ['USAGE', 'FIXED_FEE', 'TAX', 'PRO_RATA', 'WATER_USAGE', 'ELECTRIC_USAGE'], 'USAGE'),
            ];
            if (!$id) {
                $woodcrest_db->insert($woodcrest_db->invoice_line_item, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->invoice_line_item, $data, ['line_item_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-billing');
            break;

        case 'save_payment':
            $id = absint($_POST['payment_id'] ?? 0);
            $data = [
                'invoice_id' => absint($_POST['invoice_id'] ?? 0),
                'payment_date' => sanitize_text_field(wp_unslash($_POST['payment_date'] ?? '')),
                'amount' => floatval($_POST['amount'] ?? 0),
                'method' => sanitize_text_field(wp_unslash($_POST['method'] ?? 'ACH')),
                'notes' => woodcrest_power_nullable_text($_POST['notes'] ?? ''),
                'entered_by_user_id' => woodcrest_power_nullable_absint($_POST['entered_by_user_id'] ?? ''),
            ];
            if (!$id) {
                $data['created_at'] = current_time('mysql');
                $woodcrest_db->insert($woodcrest_db->payment, $data);
            } else {
                $woodcrest_db->update($woodcrest_db->payment, $data, ['payment_id' => $id]);
            }
            woodcrest_power_redirect('woodcrest-power-billing');
            break;
    }
}

function woodcrest_power_dashboard_page() {
    global $woodcrest_db;
    $counts = [
        'users (wp)' => (int) $woodcrest_db->get_var("SELECT COUNT(*) FROM {$woodcrest_db->app_user}"),
        'properties' => (int) $woodcrest_db->get_var("SELECT COUNT(*) FROM {$woodcrest_db->property}"),
        'suites' => (int) $woodcrest_db->get_var("SELECT COUNT(*) FROM {$woodcrest_db->suite}"),
        'meters' => (int) $woodcrest_db->get_var("SELECT COUNT(*) FROM {$woodcrest_db->meter}"),
        'invoices' => (int) $woodcrest_db->get_var("SELECT COUNT(*) FROM {$woodcrest_db->invoice}"),
        'payments' => (int) $woodcrest_db->get_var("SELECT COUNT(*) FROM {$woodcrest_db->payment}"),
    ];

    echo '<div class="wrap">';
    echo '<h1>Woodcrest Admin Dashboard</h1>';
    echo '<p>Admin tools for managing property assignments, meters, and billing records.</p>';
    echo '<div style="display:grid;grid-template-columns:repeat(3,minmax(220px,1fr));gap:16px;max-width:960px;margin-top:20px;">';
    foreach ($counts as $label => $value) {
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">';
        echo '<div style="font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.05em;">' . esc_html(ucfirst($label)) . '</div>';
        echo '<div style="font-size:32px;font-weight:700;line-height:1.1;margin-top:6px;">' . esc_html($value) . '</div>';
        echo '</div>';
    }
    echo '</div></div>';
}

function woodcrest_power_users_page() {
    global $woodcrest_db;

    $edit_access = woodcrest_power_edit_record($woodcrest_db->user_property_access, 'access_id', 'edit_access_id');
    $edit_occupancy = woodcrest_power_edit_record($woodcrest_db->suite_occupancy, 'occupancy_id', 'edit_occupancy_id');

    $wp_users = $woodcrest_db->get_results("SELECT ID AS user_id, display_name AS full_name FROM {$woodcrest_db->app_user} ORDER BY display_name ASC");
    $properties = $woodcrest_db->get_results("SELECT property_id, property_name FROM {$woodcrest_db->property} ORDER BY property_name ASC");
    $suites = $woodcrest_db->get_results("SELECT suite_id, suite_label, property_id FROM {$woodcrest_db->suite} ORDER BY suite_label ASC");
    
    $access_rows = $woodcrest_db->get_results("SELECT upa.access_id, upa.user_id, upa.property_id, u.display_name AS full_name, p.property_name, upa.access_scope, upa.start_date, upa.end_date FROM {$woodcrest_db->user_property_access} upa JOIN {$woodcrest_db->app_user} u ON upa.user_id=u.ID JOIN {$woodcrest_db->property} p ON upa.property_id=p.property_id ORDER BY u.display_name, p.property_name");
    
    $occupancies = $woodcrest_db->get_results("SELECT so.occupancy_id, so.tenant_user_id, so.suite_id, u.display_name AS full_name, s.suite_label, p.property_name, so.start_date, so.end_date FROM {$woodcrest_db->suite_occupancy} so JOIN {$woodcrest_db->app_user} u ON so.tenant_user_id=u.ID JOIN {$woodcrest_db->suite} s ON so.suite_id=s.suite_id JOIN {$woodcrest_db->property} p ON s.property_id=p.property_id ORDER BY p.property_name, s.suite_label");

    echo '<div class="wrap"><h1>Manage Access</h1>';
    woodcrest_power_render_form_card($edit_access ? 'Edit property access' : 'Assign manager or owner to a property', woodcrest_power_property_access_form_html($wp_users, $properties, $edit_access));
    woodcrest_power_render_form_card($edit_occupancy ? 'Edit suite occupancy' : 'Assign tenant to a suite', woodcrest_power_suite_occupancy_form_html($wp_users, $suites, $properties, $edit_occupancy));

    echo '<h2>Property Access (Managers/Owners)</h2>';
    woodcrest_power_render_table(['User', 'Property', 'Scope', 'Start', 'End', 'Actions'], array_map(function ($row) {
        return [$row->full_name, $row->property_name, $row->access_scope, $row->start_date ?: '—', $row->end_date ?: '—', woodcrest_power_edit_link('woodcrest-power-users', 'edit_access_id', $row->access_id)];
    }, $access_rows), true);

    echo '<h2>Suite Occupancy (Tenants)</h2>';
    woodcrest_power_render_table(['Tenant', 'Property', 'Suite', 'Start', 'End', 'Actions'], array_map(function ($row) {
        return [$row->full_name, $row->property_name, $row->suite_label, $row->start_date, $row->end_date ?: '—', woodcrest_power_edit_link('woodcrest-power-users', 'edit_occupancy_id', $row->occupancy_id)];
    }, $occupancies), true);
    echo '</div>';
}

function woodcrest_power_properties_page() {
    global $woodcrest_db;

    $edit_property = woodcrest_power_edit_record($woodcrest_db->property, 'property_id', 'edit_property_id');
    $edit_suite = woodcrest_power_edit_record($woodcrest_db->suite, 'suite_id', 'edit_suite_id');

    $properties = $woodcrest_db->get_results("SELECT * FROM {$woodcrest_db->property} ORDER BY property_name ASC");
    $suites = $woodcrest_db->get_results("SELECT s.*, p.property_name FROM {$woodcrest_db->suite} s JOIN {$woodcrest_db->property} p ON s.property_id=p.property_id ORDER BY p.property_name, s.suite_label");

    echo '<div class="wrap"><h1>Properties</h1>';
    woodcrest_power_render_form_card($edit_property ? 'Edit property' : 'Add property', woodcrest_power_property_form_html($edit_property));
    woodcrest_power_render_form_card($edit_suite ? 'Edit suite' : 'Add suite', woodcrest_power_suite_form_html($properties, $edit_suite));

    echo '<h2>Properties</h2>';
    woodcrest_power_render_table(['Property', 'Entity', 'Address', 'City', 'State', 'ZIP', 'Status', 'Actions'], array_map(function ($row) {
        return [$row->property_name, $row->entity_name, $row->address_line1, $row->city, $row->state, $row->zip, $row->status, woodcrest_power_edit_link('woodcrest-power-properties', 'edit_property_id', $row->property_id)];
    }, $properties), true);

    echo '<h2>Suites</h2>';
    woodcrest_power_render_table(['Property', 'Suite', 'Square feet', 'Status', 'Actions'], array_map(function ($row) {
        return [$row->property_name, $row->suite_label, $row->square_feet, $row->status, woodcrest_power_edit_link('woodcrest-power-properties', 'edit_suite_id', $row->suite_id)];
    }, $suites), true);
    echo '</div>';
}

function woodcrest_power_meters_page() {
    global $woodcrest_db;

    $edit_account = woodcrest_power_edit_record($woodcrest_db->utility_account, 'utility_account_id', 'edit_utility_account_id');
    $edit_meter = woodcrest_power_edit_record($woodcrest_db->meter, 'meter_id', 'edit_meter_id');
    $edit_resp = woodcrest_power_edit_record($woodcrest_db->meter_responsibility, 'responsibility_id', 'edit_responsibility_id');

    $properties = $woodcrest_db->get_results("SELECT property_id, property_name FROM {$woodcrest_db->property} ORDER BY property_name ASC");
    $accounts = $woodcrest_db->get_results("SELECT utility_account_id, account_number, utility_type FROM {$woodcrest_db->utility_account} ORDER BY account_number ASC");
    $meters = $woodcrest_db->get_results("SELECT m.*, p.property_name, ua.account_number FROM {$woodcrest_db->meter} m JOIN {$woodcrest_db->property} p ON m.property_id=p.property_id JOIN {$woodcrest_db->utility_account} ua ON m.utility_account_id=ua.utility_account_id ORDER BY p.property_name, m.meter_number");
    $tenants = $woodcrest_db->get_results("SELECT ID AS user_id, display_name AS full_name FROM {$woodcrest_db->app_user} ORDER BY display_name ASC");
    $suites = $woodcrest_db->get_results("SELECT s.suite_id, s.suite_label, p.property_name FROM {$woodcrest_db->suite} s JOIN {$woodcrest_db->property} p ON s.property_id=p.property_id ORDER BY p.property_name, s.suite_label");
    $responsibility = $woodcrest_db->get_results("SELECT mr.*, m.meter_number, ua.account_number, u.display_name AS full_name, s.suite_label FROM {$woodcrest_db->meter_responsibility} mr JOIN {$woodcrest_db->meter} m ON mr.meter_id=m.meter_id JOIN {$woodcrest_db->utility_account} ua ON mr.utility_account_id=ua.utility_account_id LEFT JOIN {$woodcrest_db->app_user} u ON mr.tenant_user_id=u.ID LEFT JOIN {$woodcrest_db->suite} s ON mr.suite_id=s.suite_id ORDER BY mr.created_at DESC");

    echo '<div class="wrap"><h1>Meters</h1>';
    woodcrest_power_render_form_card($edit_account ? 'Edit utility account' : 'Add utility account', woodcrest_power_utility_account_form_html($properties, $edit_account));
    woodcrest_power_render_form_card($edit_meter ? 'Edit meter' : 'Add meter', woodcrest_power_meter_form_html($properties, $accounts, $edit_meter));
    woodcrest_power_render_form_card($edit_resp ? 'Edit meter responsibility' : 'Assign meter responsibility', woodcrest_power_meter_responsibility_form_html($meters, $accounts, $tenants, $suites, $edit_resp));

    echo '<h2>Utility accounts</h2>';
    woodcrest_power_render_table(['Account', 'Type', 'Company', 'Status', 'Actions'], array_map(function ($row) {
        return [$row->account_number, $row->utility_type, $row->utility_company, $row->status, woodcrest_power_edit_link('woodcrest-power-meters', 'edit_utility_account_id', $row->utility_account_id)];
    }, $woodcrest_db->get_results("SELECT * FROM {$woodcrest_db->utility_account} ORDER BY account_number ASC")), true);

    echo '<h2>Meters</h2>';
    woodcrest_power_render_table(['Meter', 'Property', 'Account', 'Type', 'Utility', 'Status', 'Actions'], array_map(function ($row) {
        return [$row->meter_number, $row->property_name, $row->account_number, $row->meter_type, $row->utility_type, $row->status, woodcrest_power_edit_link('woodcrest-power-meters', 'edit_meter_id', $row->meter_id)];
    }, $meters), true);

    echo '<h2>Responsibilities</h2>';
    woodcrest_power_render_table(['Meter', 'Account', 'Tenant', 'Suite', 'Party type', 'Allocation', 'Billing model', 'Actions'], array_map(function ($row) {
        return [$row->meter_number, $row->account_number, $row->full_name ?: '—', $row->suite_label ?: '—', $row->responsible_party_type, $row->allocation_method, $row->billing_model, woodcrest_power_edit_link('woodcrest-power-meters', 'edit_responsibility_id', $row->responsibility_id)];
    }, $responsibility), true);
    echo '</div>';
}

function woodcrest_power_billing_page() {
    global $woodcrest_db;

    $edit_reading = woodcrest_power_edit_record($woodcrest_db->utility_reading, 'reading_id', 'edit_reading_id');
    $edit_bill = woodcrest_power_edit_record($woodcrest_db->master_utility_bill, 'bill_id', 'edit_bill_id');
    $edit_invoice = woodcrest_power_edit_record($woodcrest_db->invoice, 'invoice_id', 'edit_invoice_id');
    $edit_line_item = woodcrest_power_edit_record($woodcrest_db->invoice_line_item, 'line_item_id', 'edit_line_item_id');
    $edit_payment = woodcrest_power_edit_record($woodcrest_db->payment, 'payment_id', 'edit_payment_id');

    $users = $woodcrest_db->get_results("SELECT ID AS user_id, display_name AS full_name FROM {$woodcrest_db->app_user} ORDER BY display_name ASC");
    $properties = $woodcrest_db->get_results("SELECT property_id, property_name FROM {$woodcrest_db->property} ORDER BY property_name ASC");
    $accounts = $woodcrest_db->get_results("SELECT utility_account_id, account_number FROM {$woodcrest_db->utility_account} ORDER BY account_number ASC");
    $meters = $woodcrest_db->get_results("SELECT meter_id, meter_number FROM {$woodcrest_db->meter} ORDER BY meter_number ASC");
    $suites = $woodcrest_db->get_results("SELECT suite_id, suite_label FROM {$woodcrest_db->suite} ORDER BY suite_label ASC");
    $invoices = $woodcrest_db->get_results("SELECT invoice_id, invoice_number FROM {$woodcrest_db->invoice} ORDER BY invoice_number DESC");
    $latest_readings = $woodcrest_db->get_results("SELECT ur.reading_id, ur.reading_date, ur.usage_amount, ur.usage_unit, m.meter_number FROM {$woodcrest_db->utility_reading} ur JOIN {$woodcrest_db->meter} m ON ur.meter_id=m.meter_id ORDER BY ur.reading_date DESC LIMIT 10");
    $latest_bills = $woodcrest_db->get_results("SELECT mub.bill_id, p.property_name, ua.account_number, mub.total_amount, mub.period_end, mub.status FROM {$woodcrest_db->master_utility_bill} mub JOIN {$woodcrest_db->property} p ON mub.property_id=p.property_id JOIN {$woodcrest_db->utility_account} ua ON mub.utility_account_id=ua.utility_account_id ORDER BY mub.period_end DESC LIMIT 10");
    $latest_invoices = $woodcrest_db->get_results("SELECT i.invoice_id, i.invoice_number, i.total_amount, i.status, u.display_name AS full_name, i.period_end FROM {$woodcrest_db->invoice} i JOIN {$woodcrest_db->app_user} u ON i.tenant_user_id=u.ID ORDER BY i.period_end DESC LIMIT 10");
    $latest_payments = $woodcrest_db->get_results("SELECT p.payment_id, p.payment_date, p.amount, p.method, i.invoice_number FROM {$woodcrest_db->payment} p JOIN {$woodcrest_db->invoice} i ON p.invoice_id=i.invoice_id ORDER BY p.payment_date DESC LIMIT 10");
    $latest_line_items = $woodcrest_db->get_results("SELECT li.line_item_id, li.description, li.amount, li.line_type, i.invoice_number FROM {$woodcrest_db->invoice_line_item} li JOIN {$woodcrest_db->invoice} i ON li.invoice_id=i.invoice_id ORDER BY li.line_item_id DESC LIMIT 10");

    echo '<div class="wrap"><h1>Billing</h1>';
    woodcrest_power_render_form_card($edit_reading ? 'Edit utility reading' : 'Add utility reading', woodcrest_power_reading_form_html($meters, $users, $edit_reading));
    woodcrest_power_render_form_card($edit_bill ? 'Edit master utility bill' : 'Add master utility bill', woodcrest_power_master_bill_form_html($properties, $accounts, $meters, $users, $edit_bill));
    woodcrest_power_render_form_card($edit_invoice ? 'Edit invoice' : 'Add invoice', woodcrest_power_invoice_form_html($properties, $suites, $users, $edit_invoice));
    woodcrest_power_render_form_card($edit_line_item ? 'Edit invoice line item' : 'Add invoice line item', woodcrest_power_invoice_line_item_form_html($invoices, $edit_line_item));
    woodcrest_power_render_form_card($edit_payment ? 'Edit payment' : 'Add payment', woodcrest_power_payment_form_html($invoices, $users, $edit_payment));

    echo '<h2>Recent readings</h2>';
    woodcrest_power_render_table(['Meter', 'Reading date', 'Usage', 'Unit', 'Actions'], array_map(function ($row) {
        return [$row->meter_number, $row->reading_date, $row->usage_amount, $row->usage_unit, woodcrest_power_edit_link('woodcrest-power-billing', 'edit_reading_id', $row->reading_id)];
    }, $latest_readings), true);

    echo '<h2>Recent master bills</h2>';
    woodcrest_power_render_table(['Property', 'Account', 'Period end', 'Amount', 'Status', 'Actions'], array_map(function ($row) {
        return [$row->property_name, $row->account_number, $row->period_end, $row->total_amount, $row->status, woodcrest_power_edit_link('woodcrest-power-billing', 'edit_bill_id', $row->bill_id)];
    }, $latest_bills), true);

    echo '<h2>Recent invoices</h2>';
    woodcrest_power_render_table(['Invoice', 'Tenant', 'Period end', 'Amount', 'Status', 'Actions'], array_map(function ($row) {
        return [$row->invoice_number, $row->full_name, $row->period_end, $row->total_amount, $row->status, woodcrest_power_edit_link('woodcrest-power-billing', 'edit_invoice_id', $row->invoice_id)];
    }, $latest_invoices), true);
    echo '</div>';
}

function woodcrest_power_redirect($page_slug) {
    wp_safe_redirect(add_query_arg(['page' => $page_slug, 'updated' => '1'], admin_url('admin.php')));
    exit;
}

function woodcrest_power_enum($value, $allowed, $default) {
    $value = sanitize_text_field(wp_unslash($value));
    return in_array($value, $allowed, true) ? $value : $default;
}
function woodcrest_power_nullable_date($value) {
    $value = sanitize_text_field(wp_unslash($value));
    return $value === '' ? null : $value;
}
function woodcrest_power_nullable_datetime($value) {
    $value = sanitize_text_field(wp_unslash($value));
    return $value === '' ? null : str_replace('T', ' ', $value);
}
function woodcrest_power_nullable_text($value) {
    $value = sanitize_text_field(wp_unslash($value));
    return $value === '' ? null : $value;
}
function woodcrest_power_nullable_absint($value) {
    $value = absint($value);
    return $value > 0 ? $value : null;
}
function woodcrest_power_nullable_decimal($value) {
    return $value === '' ? null : floatval($value);
}

function woodcrest_power_render_form_card($title, $html) {
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;max-width:960px;margin:18px 0;">';
    echo '<h2 style="margin-top:0;">' . esc_html($title) . '</h2>';
    echo $html;
    echo '</div>';
}

function woodcrest_power_render_table($headers, $rows, $allow_html = false) {
    echo '<table class="widefat striped"><thead><tr>';
    foreach ($headers as $header) {
        echo '<th>' . esc_html($header) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="' . esc_attr(count($headers)) . '">No records found.</td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>';
                echo $allow_html ? wp_kses_post((string) $cell) : esc_html((string) $cell);
                echo '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
}

function woodcrest_power_edit_record($table, $pk, $param) {
    global $woodcrest_db;
    $id = absint($_GET[$param] ?? 0);
    if (!$id) return null;
    return $woodcrest_db->get_row($woodcrest_db->prepare("SELECT * FROM {$table} WHERE {$pk} = %d", $id));
}

function woodcrest_power_edit_link($page, $param, $id) {
    $url = add_query_arg(['page' => $page, $param => (int) $id], admin_url('admin.php'));
    return '<a class="button button-small" href="' . esc_url($url) . '">Edit</a>';
}

function woodcrest_power_admin_form_start() {
    ob_start();
    echo '<form method="post" style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:14px;align-items:end;">';
    wp_nonce_field('woodcrest_power_admin_action', 'woodcrest_nonce');
    return ob_get_clean();
}

function woodcrest_power_field($label, $name, $type = 'text', $value = '', $required = false, $options = []) {
    ob_start();
    echo '<div>';
    echo '<label style="display:block;font-weight:600;margin-bottom:6px;">' . esc_html($label) . '</label>';
    if ($type === 'select') {
        echo '<select name="' . esc_attr($name) . '" ' . ($required ? 'required' : '') . ' style="width:100%;">';
        echo '<option value="">Select</option>';
        foreach ($options as $opt_value => $opt_label) {
            $selected = selected((string) $value, (string) $opt_value, false);
            echo '<option value="' . esc_attr($opt_value) . '" ' . $selected . '>' . esc_html($opt_label) . '</option>';
        }
        echo '</select>';
    } elseif ($type === 'textarea') {
        echo '<textarea name="' . esc_attr($name) . '" rows="3" style="width:100%;">' . esc_textarea($value) . '</textarea>';
    } else {
        echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" ' . ($required ? 'required' : '') . ' style="width:100%;" />';
    }
    echo '</div>';
    return ob_get_clean();
}

function woodcrest_power_submit($action, $button = 'Save') {
    return '<input type="hidden" name="woodcrest_action" value="' . esc_attr($action) . '" /><div style="grid-column:1 / -1;"><button class="button button-primary" type="submit">' . esc_html($button) . '</button></div></form>';
}

function woodcrest_power_property_access_form_html($users, $properties, $record = null) {
    $user_options = [];
    foreach ($users as $user) { $user_options[$user->user_id] = $user->full_name; }
    $property_options = [];
    foreach ($properties as $property) { $property_options[$property->property_id] = $property->property_name; }
    
    $val_id = $record?->access_id ?? '';
    $val_user = $record?->user_id ?? '';
    $val_prop = $record?->property_id ?? '';
    $val_scope = $record?->access_scope ?? '';
    $val_start = $record?->start_date ?? '';
    $val_end = $record?->end_date ?? '';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="access_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Manager or owner', 'user_id', 'select', $val_user, true, $user_options);
    $html .= woodcrest_power_field('Property', 'property_id', 'select', $val_prop, true, $property_options);
    $html .= woodcrest_power_field('Access scope', 'access_scope', 'select', $val_scope, true, ['PROPERTY_MANAGER' => 'Property Manager', 'OWNER' => 'Owner']);
    $html .= woodcrest_power_field('Start date', 'start_date', 'date', $val_start);
    $html .= woodcrest_power_field('End date', 'end_date', 'date', $val_end);
    $html .= woodcrest_power_submit('assign_property_access', $record ? 'Update property access' : 'Assign property access');
    return $html;
}

function woodcrest_power_suite_occupancy_form_html($tenants, $suites, $properties, $record = null) {
    $tenant_options = [];
    foreach ($tenants as $tenant) { $tenant_options[$tenant->user_id] = $tenant->full_name; }
    $suite_options = [];
    $property_names = [];
    foreach ($properties as $property) { $property_names[$property->property_id] = $property->property_name; }
    foreach ($suites as $suite) { $suite_options[$suite->suite_id] = ($property_names[$suite->property_id] ?? 'Property') . ' - ' . $suite->suite_label; }
    
    $val_id = $record?->occupancy_id ?? '';
    $val_tenant = $record?->tenant_user_id ?? '';
    $val_suite = $record?->suite_id ?? '';
    $val_start = $record?->start_date ?? '';
    $val_end = $record?->end_date ?? '';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="occupancy_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Tenant', 'tenant_user_id', 'select', $val_tenant, true, $tenant_options);
    $html .= woodcrest_power_field('Suite', 'suite_id', 'select', $val_suite, true, $suite_options);
    $html .= woodcrest_power_field('Start date', 'start_date', 'date', $val_start, true);
    $html .= woodcrest_power_field('End date', 'end_date', 'date', $val_end);
    $html .= woodcrest_power_submit('assign_suite_occupancy', $record ? 'Update occupancy' : 'Assign suite');
    return $html;
}

function woodcrest_power_property_form_html($record = null) {
    $val_id = $record?->property_id ?? '';
    $val_entity = $record?->entity_name ?? '';
    $val_name = $record?->property_name ?? '';
    $val_addr = $record?->address_line1 ?? '';
    $val_city = $record?->city ?? '';
    $val_state = $record?->state ?? '';
    $val_zip = $record?->zip ?? '';
    $val_tz = $record?->timezone ?? 'America/Chicago';
    $val_status = $record?->status ?? 'ACTIVE';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="property_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Entity name', 'entity_name', 'text', $val_entity, true);
    $html .= woodcrest_power_field('Property name', 'property_name', 'text', $val_name, true);
    $html .= woodcrest_power_field('Address', 'address_line1', 'text', $val_addr, true);
    $html .= woodcrest_power_field('City', 'city', 'text', $val_city, true);
    $html .= woodcrest_power_field('State', 'state', 'text', $val_state, true);
    $html .= woodcrest_power_field('ZIP', 'zip', 'text', $val_zip, true);
    $html .= woodcrest_power_field('Timezone', 'timezone', 'text', $val_tz, true);
    $html .= woodcrest_power_field('Status', 'status', 'select', $val_status, true, ['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive']);
    $html .= woodcrest_power_submit('save_property', $record ? 'Update property' : 'Add property');
    return $html;
}

function woodcrest_power_suite_form_html($properties, $record = null) {
    $property_options = [];
    foreach ($properties as $property) { $property_options[$property->property_id] = $property->property_name; }
    
    $val_id = $record?->suite_id ?? '';
    $val_prop = $record?->property_id ?? '';
    $val_label = $record?->suite_label ?? '';
    $val_sqft = $record?->square_feet ?? '';
    $val_status = $record?->status ?? 'VACANT';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="suite_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Property', 'property_id', 'select', $val_prop, true, $property_options);
    $html .= woodcrest_power_field('Suite label', 'suite_label', 'text', $val_label, true);
    $html .= woodcrest_power_field('Square feet', 'square_feet', 'number', $val_sqft, true);
    $html .= woodcrest_power_field('Status', 'status', 'select', $val_status, true, ['OCCUPIED' => 'Occupied', 'VACANT' => 'Vacant']);
    $html .= woodcrest_power_submit('save_suite', $record ? 'Update suite' : 'Add suite');
    return $html;
}

function woodcrest_power_utility_account_form_html($properties, $record = null) {
    $property_options = [];
    foreach ($properties as $property) { $property_options[$property->property_id] = $property->property_name; }
    
    $val_id = $record?->utility_account_id ?? '';
    $val_prop = $record?->property_id ?? '';
    $val_type = $record?->utility_type ?? 'ELECTRIC';
    $val_acc = $record?->account_number ?? '';
    $val_esi = $record?->esi_id ?? '';
    $val_comp = $record?->utility_company ?? '';
    $val_serv = $record?->service_address ?? '';
    $val_desc = $record?->account_description ?? '';
    $val_stat = $record?->status ?? 'ACTIVE';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="utility_account_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Property', 'property_id', 'select', $val_prop, true, $property_options);
    $html .= woodcrest_power_field('Utility type', 'utility_type', 'select', $val_type, true, ['ELECTRIC' => 'Electric', 'WATER' => 'Water']);
    $html .= woodcrest_power_field('Account number', 'account_number', 'text', $val_acc, true);
    $html .= woodcrest_power_field('ESI ID', 'esi_id', 'text', $val_esi);
    $html .= woodcrest_power_field('Utility company', 'utility_company', 'text', $val_comp, true);
    $html .= woodcrest_power_field('Service address', 'service_address', 'text', $val_serv);
    $html .= woodcrest_power_field('Description', 'account_description', 'text', $val_desc);
    $html .= woodcrest_power_field('Status', 'status', 'select', $val_stat, true, ['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive']);
    $html .= woodcrest_power_submit('save_utility_account', $record ? 'Update utility account' : 'Add utility account');
    return $html;
}

function woodcrest_power_meter_form_html($properties, $accounts, $record = null) {
    $property_options = [];
    foreach ($properties as $property) { $property_options[$property->property_id] = $property->property_name; }
    $account_options = [];
    foreach ($accounts as $account) { $account_options[$account->utility_account_id] = $account->account_number . ' (' . $account->utility_type . ')'; }
    
    $val_id = $record?->meter_id ?? '';
    $val_prop = $record?->property_id ?? '';
    $val_uacc = $record?->utility_account_id ?? '';
    $val_num = $record?->meter_number ?? '';
    $val_type = $record?->meter_type ?? 'SUBMETER';
    $val_utype = $record?->utility_type ?? 'ELECTRIC';
    $val_size = $record?->meter_size ?? '';
    $val_name = $record?->meter_name ?? '';
    $val_sn = $record?->serial_number ?? '';
    $val_stat = $record?->status ?? 'ACTIVE';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="meter_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Property', 'property_id', 'select', $val_prop, true, $property_options);
    $html .= woodcrest_power_field('Utility account', 'utility_account_id', 'select', $val_uacc, true, $account_options);
    $html .= woodcrest_power_field('Meter number', 'meter_number', 'text', $val_num, true);
    $html .= woodcrest_power_field('Meter type', 'meter_type', 'select', $val_type, true, ['MASTER' => 'Master', 'SUBMETER' => 'Submeter']);
    $html .= woodcrest_power_field('Utility type', 'utility_type', 'select', $val_utype, true, ['ELECTRIC' => 'Electric', 'WATER' => 'Water']);
    $html .= woodcrest_power_field('Meter size', 'meter_size', 'text', $val_size);
    $html .= woodcrest_power_field('Meter name', 'meter_name', 'text', $val_name);
    $html .= woodcrest_power_field('Serial number', 'serial_number', 'text', $val_sn);
    $html .= woodcrest_power_field('Status', 'status', 'select', $val_stat, true, ['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive']);
    $html .= woodcrest_power_submit('save_meter', $record ? 'Update meter' : 'Add meter');
    return $html;
}

function woodcrest_power_meter_responsibility_form_html($meters, $accounts, $tenants, $suites, $record = null) {
    $meter_options = []; foreach ($meters as $meter) { $meter_options[$meter->meter_id] = $meter->meter_number; }
    $account_options = []; foreach ($accounts as $account) { $account_options[$account->utility_account_id] = $account->account_number; }
    $tenant_options = []; foreach ($tenants as $tenant) { $tenant_options[$tenant->user_id] = $tenant->full_name; }
    $suite_options = []; foreach ($suites as $suite) { $suite_options[$suite->suite_id] = $suite->property_name . ' - ' . $suite->suite_label; }
    
    $val_id = $record?->responsibility_id ?? '';
    $val_meter = $record?->meter_id ?? '';
    $val_uacc = $record?->utility_account_id ?? '';
    $val_tenant = $record?->tenant_user_id ?? '';
    $val_suite = $record?->suite_id ?? '';
    $val_desc = $record?->description ?? '';
    $val_party = $record?->responsible_party_type ?? 'TENANT';
    $val_alloc = $record?->allocation_method ?? 'ACTUAL';
    $val_fixed = $record?->fixed_percent ?? '';
    $val_bill = $record?->billing_model ?? 'PASS_THROUGH';
    $val_charge = $record?->monthly_charge_amount ?? '';
    $val_start = $record?->start_date ?? '';
    $val_end = $record?->end_date ?? '';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="responsibility_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Meter', 'meter_id', 'select', $val_meter, true, $meter_options);
    $html .= woodcrest_power_field('Utility account', 'utility_account_id', 'select', $val_uacc, true, $account_options);
    $html .= woodcrest_power_field('Tenant', 'tenant_user_id', 'select', $val_tenant, false, $tenant_options);
    $html .= woodcrest_power_field('Suite', 'suite_id', 'select', $val_suite, false, $suite_options);
    $html .= woodcrest_power_field('Description', 'description', 'text', $val_desc);
    $html .= woodcrest_power_field('Responsible party type', 'responsible_party_type', 'select', $val_party, true, ['TENANT' => 'Tenant', 'VACANT_WOODCREST' => 'Vacant / Woodcrest', 'CAM' => 'CAM']);
    $html .= woodcrest_power_field('Allocation method', 'allocation_method', 'select', $val_alloc, true, ['ACTUAL' => 'Actual', 'PRO_RATA_SF' => 'Pro rata square feet', 'FIXED_PERCENT' => 'Fixed percent']);
    $html .= woodcrest_power_field('Fixed percent', 'fixed_percent', 'number', $val_fixed);
    $html .= woodcrest_power_field('Billing model', 'billing_model', 'select', $val_bill, true, ['PASS_THROUGH' => 'Pass through', 'RESALE' => 'Resale']);
    $html .= woodcrest_power_field('Monthly charge amount', 'monthly_charge_amount', 'number', $val_charge);
    $html .= woodcrest_power_field('Start date', 'start_date', 'date', $val_start, true);
    $html .= woodcrest_power_field('End date', 'end_date', 'date', $val_end);
    $html .= woodcrest_power_submit('assign_meter_responsibility', $record ? 'Update responsibility' : 'Assign meter');
    return $html;
}

function woodcrest_power_reading_form_html($meters, $users, $record = null) {
    $meter_options = []; foreach ($meters as $meter) { $meter_options[$meter->meter_id] = $meter->meter_number; }
    $user_options = []; foreach ($users as $user) { $user_options[$user->user_id] = $user->full_name; }
    
    $val_id = $record?->reading_id ?? '';
    $val_meter = $record?->meter_id ?? '';
    $val_date = $record?->reading_date ?? '';
    $val_curr = $record?->current_read ?? '';
    $val_prev = $record?->previous_read ?? '';
    $val_unit = $record?->usage_unit ?? 'kWh';
    $val_user = $record?->entered_by_user_id ?? '';
    $val_locked = !empty($record->locked) ? ' checked' : '';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="reading_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Meter', 'meter_id', 'select', $val_meter, true, $meter_options);
    $html .= woodcrest_power_field('Reading date', 'reading_date', 'date', $val_date, true);
    $html .= woodcrest_power_field('Current read', 'current_read', 'number', $val_curr, true);
    $html .= woodcrest_power_field('Previous read', 'previous_read', 'number', $val_prev, true);
    $html .= woodcrest_power_field('Usage unit', 'usage_unit', 'text', $val_unit, true);
    $html .= woodcrest_power_field('Entered by', 'entered_by_user_id', 'select', $val_user, false, $user_options);
    $html .= '<div><label><input type="checkbox" name="locked" value="1"' . $val_locked . ' /> Lock reading</label></div>';
    $html .= woodcrest_power_submit('save_reading', $record ? 'Update reading' : 'Add reading');
    return $html;
}

function woodcrest_power_master_bill_form_html($properties, $accounts, $meters, $users, $record = null) {
    $property_options = []; foreach ($properties as $property) { $property_options[$property->property_id] = $property->property_name; }
    $account_options = []; foreach ($accounts as $account) { $account_options[$account->utility_account_id] = $account->account_number; }
    $meter_options = []; foreach ($meters as $meter) { $meter_options[$meter->meter_id] = $meter->meter_number; }
    $user_options = []; foreach ($users as $user) { $user_options[$user->user_id] = $user->full_name; }
    
    $val_id = $record?->bill_id ?? '';
    $val_prop = $record?->property_id ?? '';
    $val_uacc = $record?->utility_account_id ?? '';
    $val_mm = $record?->master_meter_id ?? '';
    $val_start = $record?->period_start ?? '';
    $val_end = $record?->period_end ?? '';
    $val_total = $record?->total_amount ?? '';
    $val_stat = $record?->status ?? 'DRAFT';
    $val_user = $record?->created_by_user_id ?? '';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="bill_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Property', 'property_id', 'select', $val_prop, true, $property_options);
    $html .= woodcrest_power_field('Utility account', 'utility_account_id', 'select', $val_uacc, true, $account_options);
    $html .= woodcrest_power_field('Master meter', 'master_meter_id', 'select', $val_mm, true, $meter_options);
    $html .= woodcrest_power_field('Period start', 'period_start', 'date', $val_start, true);
    $html .= woodcrest_power_field('Period end', 'period_end', 'date', $val_end, true);
    $html .= woodcrest_power_field('Total amount', 'total_amount', 'number', $val_total, true);
    $html .= woodcrest_power_field('Status', 'status', 'select', $val_stat, true, ['DRAFT' => 'Draft', 'CONFIRMED' => 'Confirmed', 'LOCKED' => 'Locked']);
    $html .= woodcrest_power_field('Created by', 'created_by_user_id', 'select', $val_user, true, $user_options);
    $html .= woodcrest_power_submit('save_master_bill', $record ? 'Update master bill' : 'Add master bill');
    return $html;
}

function woodcrest_power_invoice_form_html($properties, $suites, $users, $record = null) {
    $property_options = []; foreach ($properties as $property) { $property_options[$property->property_id] = $property->property_name; }
    $suite_options = []; foreach ($suites as $suite) { $suite_options[$suite->suite_id] = $suite->suite_label; }
    $user_options = []; foreach ($users as $user) { $user_options[$user->user_id] = $user->full_name; }
    
    $val_id = $record?->invoice_id ?? '';
    $val_num = $record?->invoice_number ?? '';
    $val_prop = $record?->property_id ?? '';
    $val_suite = $record?->suite_id ?? '';
    $val_tenant = $record?->tenant_user_id ?? '';
    $val_start = $record?->period_start ?? '';
    $val_end = $record?->period_end ?? '';
    $val_snap = $record?->tenant_name_snapshot ?? '';
    $val_attn = $record?->attn ?? '';
    $val_sqft = $record?->square_footage_snapshot ?? '';
    $val_rata = $record?->pro_rata ?? '';
    $val_port = $record?->pro_rata_portion ?? '';
    $val_sub = $record?->subtotal ?? '';
    $val_tax = $record?->tax_total ?? '';
    $val_total = $record?->total_amount ?? '';
    $val_stat = $record?->status ?? 'DRAFT';
    $val_conf = empty($record->confirmed_at) ? '' : str_replace(' ', 'T', substr($record->confirmed_at, 0, 16));
    $val_cuser = $record?->confirmed_by_user_id ?? '';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="invoice_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Invoice number', 'invoice_number', 'text', $val_num, true);
    $html .= woodcrest_power_field('Property', 'property_id', 'select', $val_prop, true, $property_options);
    $html .= woodcrest_power_field('Suite', 'suite_id', 'select', $val_suite, true, $suite_options);
    $html .= woodcrest_power_field('Tenant', 'tenant_user_id', 'select', $val_tenant, true, $user_options);
    $html .= woodcrest_power_field('Period start', 'period_start', 'date', $val_start, true);
    $html .= woodcrest_power_field('Period end', 'period_end', 'date', $val_end, true);
    $html .= woodcrest_power_field('Tenant name snapshot', 'tenant_name_snapshot', 'text', $val_snap, true);
    $html .= woodcrest_power_field('Attn', 'attn', 'text', $val_attn);
    $html .= woodcrest_power_field('Square footage snapshot', 'square_footage_snapshot', 'number', $val_sqft);
    $html .= woodcrest_power_field('Pro rata', 'pro_rata', 'number', $val_rata);
    $html .= woodcrest_power_field('Pro rata portion', 'pro_rata_portion', 'number', $val_port);
    $html .= woodcrest_power_field('Subtotal', 'subtotal', 'number', $val_sub, true);
    $html .= woodcrest_power_field('Tax total', 'tax_total', 'number', $val_tax, true);
    $html .= woodcrest_power_field('Total amount', 'total_amount', 'number', $val_total, true);
    $html .= woodcrest_power_field('Status', 'status', 'select', $val_stat, true, ['DRAFT' => 'Draft', 'CONFIRMED' => 'Confirmed', 'VOIDED' => 'Voided', 'SENT' => 'Sent']);
    $html .= woodcrest_power_field('Confirmed at', 'confirmed_at', 'datetime-local', $val_conf);
    $html .= woodcrest_power_field('Confirmed by', 'confirmed_by_user_id', 'select', $val_cuser, false, $user_options);
    $html .= woodcrest_power_submit('save_invoice', $record ? 'Update invoice' : 'Add invoice');
    return $html;
}

function woodcrest_power_invoice_line_item_form_html($invoices, $record = null) {
    $invoice_options = []; foreach ($invoices as $invoice) { $invoice_options[$invoice->invoice_id] = $invoice->invoice_number; }
    
    $val_id = $record?->line_item_id ?? '';
    $val_inv = $record?->invoice_id ?? '';
    $val_desc = $record?->description ?? '';
    $val_qty = $record?->quantity ?? '';
    $val_price = $record?->unit_price ?? '';
    $val_amt = $record?->amount ?? '';
    $val_type = $record?->line_type ?? 'USAGE';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="line_item_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Invoice', 'invoice_id', 'select', $val_inv, true, $invoice_options);
    $html .= woodcrest_power_field('Description', 'description', 'text', $val_desc, true);
    $html .= woodcrest_power_field('Quantity', 'quantity', 'number', $val_qty, true);
    $html .= woodcrest_power_field('Unit price', 'unit_price', 'number', $val_price, true);
    $html .= woodcrest_power_field('Amount', 'amount', 'number', $val_amt, true);
    $html .= woodcrest_power_field('Line type', 'line_type', 'select', $val_type, true, ['USAGE' => 'Usage', 'FIXED_FEE' => 'Fixed fee', 'TAX' => 'Tax', 'PRO_RATA' => 'Pro rata', 'WATER_USAGE' => 'Water usage', 'ELECTRIC_USAGE' => 'Electric usage']);
    $html .= woodcrest_power_submit('save_invoice_line_item', $record ? 'Update line item' : 'Add line item');
    return $html;
}

function woodcrest_power_payment_form_html($invoices, $users, $record = null) {
    $invoice_options = []; foreach ($invoices as $invoice) { $invoice_options[$invoice->invoice_id] = $invoice->invoice_number; }
    $user_options = []; foreach ($users as $user) { $user_options[$user->user_id] = $user->full_name; }
    
    $val_id = $record?->payment_id ?? '';
    $val_inv = $record?->invoice_id ?? '';
    $val_date = $record?->payment_date ?? '';
    $val_amt = $record?->amount ?? '';
    $val_meth = $record?->method ?? 'ACH';
    $val_user = $record?->entered_by_user_id ?? '';
    $val_notes = $record?->notes ?? '';

    $html = woodcrest_power_admin_form_start();
    $html .= '<input type="hidden" name="payment_id" value="' . esc_attr($val_id) . '" />';
    $html .= woodcrest_power_field('Invoice', 'invoice_id', 'select', $val_inv, true, $invoice_options);
    $html .= woodcrest_power_field('Payment date', 'payment_date', 'date', $val_date, true);
    $html .= woodcrest_power_field('Amount', 'amount', 'number', $val_amt, true);
    $html .= woodcrest_power_field('Method', 'method', 'select', $val_meth, true, ['ACH' => 'ACH', 'CHECK' => 'Check', 'CASH' => 'Cash', 'CARD' => 'Card', 'WIRE' => 'Wire', 'OTHER' => 'Other']);
    $html .= woodcrest_power_field('Entered by', 'entered_by_user_id', 'select', $val_user, false, $user_options);
    $html .= woodcrest_power_field('Notes', 'notes', 'textarea', $val_notes);
    $html .= woodcrest_power_submit('save_payment', $record ? 'Update payment' : 'Add payment');
    return $html;
}
