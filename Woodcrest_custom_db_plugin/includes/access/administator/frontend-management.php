<?php
function woodcrest_fe_access_message($type) {
    if (!is_user_logged_in()) {
        return '<div class="woodcrest-card"><strong>Please sign in.</strong></div>';
    }

    if ($type === 'admin' && !current_user_can('manage_options')) {
        return '<div class="woodcrest-card"><strong>Access denied.</strong> This module is for Woodcrest administrators.</div>';
    }

    if ($type === 'manager') {
        $manager_id = woodcrest_fe_effective_manager_id();
        if (!$manager_id || (!current_user_can('manage_options') && !woodcrest_fe_is_property_manager($manager_id))) {
            return '<div class="woodcrest-card"><strong>Access denied.</strong> You are not assigned as a property manager.</div>';
        }
    }

    return '';
}

function woodcrest_fe_module_wrap($html) {
    return '<div class="woodcrest-fe-module">' . $html . '</div>';
}

function woodcrest_fe_manager_module_wrap($html) {
    return '<div class="woodcrest-fe-module wc-role-manager-content">' . $html . '</div>';
}

function woodcrest_fe_admin_module_wrap($html) {
    return '<div class="woodcrest-fe-module wc-role-admin">' . $html . '</div>';
}


function woodcrest_fe_role_header_html($type) {
    if (!is_user_logged_in()) {
        return '<div class="woodcrest-card"><strong>Please sign in.</strong></div>';
    }

    if ($type === 'admin') {
        if (!current_user_can('manage_options')) {
            return '<div class="woodcrest-card"><strong>Access denied.</strong> This page is for Woodcrest administrators.</div>';
        }
        $badge = 'Woodcrest Admin';
        $description = 'Manage properties, users, access, utilities, and billing across your portfolio.';
        $badge_class = ' admin';
        $header_class = ' woodcrest-fe-admin-header wc-role-admin';
    } else {
        $manager_id = woodcrest_fe_effective_manager_id();
        if (!$manager_id || (!current_user_can('manage_options') && !woodcrest_fe_is_property_manager($manager_id))) {
            return '<div class="woodcrest-card"><strong>Access denied.</strong> You are not assigned as a property manager.</div>';
        }
        $badge = 'Property Manager';
        $description = 'Manage tenants, suites, utility rates, accounts, and meters for your assigned properties.';
        $badge_class = ' manager';
        $header_class = ' woodcrest-fe-manager-header wc-role-manager';
    }

    $user = ($type === 'manager') ? get_userdata(woodcrest_fe_effective_manager_id()) : wp_get_current_user();
    if (!$user) $user = wp_get_current_user();

    ob_start(); ?>
    <div class="woodcrest-fe-page woodcrest-fe-user-header<?php echo esc_attr($header_class); ?>">
        <?php echo woodcrest_fe_notice_html(); ?>
        <div class="woodcrest-header-box">
            <div style="display:flex;justify-content:space-between;gap:20px;align-items:flex-start;flex-wrap:wrap;">
                <div>
                    <div class="woodcrest-header-badge<?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge); ?></div>
                    <h1>Welcome, <?php echo esc_html($user->display_name ?: $user->user_login); ?></h1>
                    <p><?php echo esc_html($description); ?></p>
                </div>
                <?php echo woodcrest_fe_header_nav_html($type === 'admin' ? 'admin' : 'manager'); ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function woodcrest_admin_header_html() {
    return woodcrest_fe_role_header_html('admin');
}
add_shortcode('woodcrest_admin_header', 'woodcrest_admin_header_html');
add_shortcode('woodcrest_owner_admin_header', 'woodcrest_admin_header_html');

function woodcrest_fe_pm_admin_header_html() {
    return woodcrest_fe_role_header_html('manager');
}
add_shortcode('woodcrest_pm_admin_header', 'woodcrest_fe_pm_admin_header_html');


function woodcrest_management_header_html() {
    if (!is_user_logged_in()) {
        return '<div class="woodcrest-card"><strong>Please sign in.</strong></div>';
    }

    if (current_user_can('manage_options')) {
        return woodcrest_admin_header_html();
    }

    if (woodcrest_fe_is_property_manager()) {
        return woodcrest_fe_pm_admin_header_html();
    }

    $user = wp_get_current_user();
    return '<div class="woodcrest-fe-page woodcrest-fe-user-header"><div class="woodcrest-header-box"><div class="woodcrest-header-badge">Woodcrest</div><h1>Welcome, ' . esc_html($user->display_name ?: $user->user_login) . '</h1><p>Your Woodcrest account.</p></div></div>';
}
add_shortcode('woodcrest_management_header', 'woodcrest_management_header_html');





function woodcrest_admin_unassigned_properties_html() {
    if ($gate = woodcrest_fe_access_message('admin')) return $gate;
    global $wpdb;
    $t = woodcrest_fe_tables();
    $today = current_time('Y-m-d');
    $properties = $wpdb->get_results($wpdb->prepare(
        "SELECT p.property_name, p.entity_name, p.city, p.state, p.status
         FROM {$t['property']} p
         WHERE NOT EXISTS (
             SELECT 1 FROM {$t['user_property_access']} upa
             WHERE upa.property_id = p.property_id
               AND upa.access_scope = 'PROPERTY_MANAGER'
               AND (upa.start_date IS NULL OR upa.start_date <= %s)
               AND (upa.end_date IS NULL OR upa.end_date > %s)
         )
         ORDER BY p.property_name", $today, $today
    ));
    $properties = (array)$properties;
    $rows = [];
    foreach ($properties as $p) {
        $rows[] = [esc_html($p->property_name), esc_html($p->entity_name),
            esc_html(trim($p->city . ', ' . $p->state, ' ,')), esc_html($p->status)];
    }
    return woodcrest_fe_admin_module_wrap(
        '<section class="woodcrest-card woodcrest-card-wide wc-unassigned-properties"><div class="woodcrest-card-header"><h2>Unassigned Properties (' . count($properties) . ')</h2></div>' .
        woodcrest_fe_table(['Property', 'Entity', 'Location', 'Status'], $rows) . '</section>'
    );
}
add_shortcode('woodcrest_admin_unassigned_properties', 'woodcrest_admin_unassigned_properties_html');

function woodcrest_admin_properties_html() {
    if ($gate = woodcrest_fe_access_message('admin')) return $gate;

    global $wpdb;
    $t = woodcrest_fe_tables();
    $properties = $wpdb->get_results(
        "SELECT
            p.*,
            (
                SELECT COUNT(*)
                FROM {$t['suite']} s
                WHERE s.property_id = p.property_id
            ) AS managed_suites
         FROM {$t['property']} p
         ORDER BY p.property_name"
    );
    $edit_property = woodcrest_fe_get_record($t['property'], 'property_id', 'wc_edit_property');

    $property_form = woodcrest_fe_form_start('save_property');
    $property_form .= '<input type="hidden" name="property_id" value="' . esc_attr($edit_property->property_id ?? '') . '">';
    $property_form .= woodcrest_fe_field('Entity name', 'entity_name', 'text', $edit_property->entity_name ?? '', true);
    $property_form .= woodcrest_fe_field('Property name', 'property_name', 'text', $edit_property->property_name ?? '', true);
    $property_form .= woodcrest_fe_field('Address', 'address_line1', 'text', $edit_property->address_line1 ?? '', true);
    $property_form .= woodcrest_fe_field('City', 'city', 'text', $edit_property->city ?? '', true);
    $property_form .= woodcrest_fe_field('State', 'state', 'text', $edit_property->state ?? '', true);
    $property_form .= woodcrest_fe_field('ZIP', 'zip', 'text', $edit_property->zip ?? '', true);
    $property_form .= woodcrest_fe_field('Timezone', 'timezone', 'text', $edit_property->timezone ?? 'America/Chicago', true);
    $property_form .= woodcrest_fe_field('Status', 'status', 'select', $edit_property->status ?? 'ACTIVE', true, ['ACTIVE'=>'Active','INACTIVE'=>'Inactive']);
    $property_form .= woodcrest_fe_form_end($edit_property ? 'Update property' : 'Create property');
    if ($edit_property) {
        $delete_plan = woodcrest_fe_property_delete_plan((int)$edit_property->property_id);
        $delete_confirmation = '<details class="wc-delete-property"><summary>Delete Property</summary><div class="wc-delete-property-confirm">';
        if (is_wp_error($delete_plan)) {
            $delete_confirmation .= '<p>' . esc_html($delete_plan->get_error_message()) . '</p>';
        } else {
            $counts = $delete_plan['ids'];
            $delete_confirmation .= '<p><strong>Permanent deletion:</strong> ' . esc_html($delete_plan['property_name']) . '</p>';
            $delete_confirmation .= '<p>This removes ' . count($counts['suite']) . ' suites, ' . count($counts['meter']) . ' meters, ' . count($counts['invoice']) . ' invoices, ' . count($counts['utility_reading']) . ' readings, ' . count($counts['nnn_reconciliation']) . ' Triple Net reconciliations, and related property records. Shared WordPress user accounts are not deleted.</p>';
            $delete_confirmation .= woodcrest_fe_form_start('delete_property');
            $delete_confirmation .= '<input type="hidden" name="property_id" value="' . (int)$edit_property->property_id . '">';
            $delete_confirmation .= '<div class="woodcrest-fe-field"><label>Type DELETE to confirm</label><input name="delete_confirm" type="text" pattern="DELETE" placeholder="DELETE" autocomplete="off" required></div>';
            $delete_confirmation .= '<div class="woodcrest-fe-submit"><button type="submit" class="woodcrest-fe-button wc-delete-property-final">Permanently Delete Property</button></div></form>';
        }
        $property_form .= $delete_confirmation . '</div></details>';
    }


    $rows = [];
    foreach ($properties as $p) {
        $rows[] = [
            esc_html($p->property_name),
            esc_html($p->entity_name),
            esc_html($p->city . ', ' . $p->state),
            (int) $p->managed_suites,
            esc_html($p->status),
            woodcrest_fe_edit_link('wc_edit_property', $p->property_id),
        ];
    }

    $html  = woodcrest_fe_card($edit_property ? 'Edit Property' : 'Create Property', $property_form . woodcrest_v24_inline_csv('properties'), (bool) $edit_property);
    $html .= '<section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Properties</h2></div>';
    $html .= woodcrest_fe_table(['Property','Entity','Location','Managed Suites','Status','Action'], $rows);
    $html .= '</section>';
    $html .= woodcrest_admin_unassigned_properties_html();

    return woodcrest_fe_admin_module_wrap($html);
}
add_shortcode('woodcrest_admin_properties', 'woodcrest_admin_properties_html');


function woodcrest_admin_property_managers_html() {
    if ($gate = woodcrest_fe_access_message('admin')) return $gate;

    global $wpdb;
    $t = woodcrest_fe_tables();
    $today = current_time('Y-m-d');

    
    
    
    $properties = $wpdb->get_results(
        "SELECT property_id, property_name, status FROM {$t['property']} ORDER BY property_name, property_id"
    );
    $users = get_users([
        'orderby' => 'display_name',
        'order'   => 'ASC',
    ]);
    $assignments = $wpdb->get_results($wpdb->prepare(
        "SELECT
            upa.*,
            u.display_name,
            u.user_login,
            u.user_email,
            p.property_name,
            (
                SELECT COUNT(DISTINCT so.tenant_user_id)
                FROM {$t['suite']} s_tenant
                JOIN {$t['suite_occupancy']} so ON so.suite_id = s_tenant.suite_id
                WHERE s_tenant.property_id = upa.property_id
                  AND so.start_date <= CURDATE()
                  AND (so.end_date IS NULL OR so.end_date >= CURDATE())
            ) AS assigned_tenants,
            (
                SELECT COUNT(*)
                FROM {$t['suite']} s_total
                WHERE s_total.property_id = upa.property_id
            ) AS managed_suites,
            (
                SELECT COUNT(*)
                FROM {$t['suite']} s_occupied
                WHERE s_occupied.property_id = upa.property_id
                  AND EXISTS (
                      SELECT 1
                      FROM {$t['suite_occupancy']} so_active
                      WHERE so_active.suite_id = s_occupied.suite_id
                        AND so_active.start_date <= CURDATE()
                        AND (so_active.end_date IS NULL OR so_active.end_date >= CURDATE())
                  )
            ) AS occupied_suites,
            (
                SELECT COUNT(*)
                FROM {$t['suite']} s_vacant
                WHERE s_vacant.property_id = upa.property_id
                  AND NOT EXISTS (
                      SELECT 1
                      FROM {$t['suite_occupancy']} so_active
                      WHERE so_active.suite_id = s_vacant.suite_id
                        AND so_active.start_date <= CURDATE()
                        AND (so_active.end_date IS NULL OR so_active.end_date >= CURDATE())
                  )
            ) AS vacant_suites
         FROM {$t['user_property_access']} upa
         JOIN {$t['users']} u ON u.ID = upa.user_id
         JOIN {$t['property']} p ON p.property_id = upa.property_id
         WHERE upa.access_scope = 'PROPERTY_MANAGER'
           AND (upa.start_date IS NULL OR upa.start_date <= %s)
           AND (upa.end_date IS NULL OR upa.end_date > %s)
         ORDER BY p.property_name, u.display_name", $today, $today
    ));

    $property_options = [];
    foreach ($properties as $p) {
        $label = $p->property_name;
        if (!empty($p->status) && strtoupper((string) $p->status) !== 'ACTIVE') {
            $label .= ' — ' . ucfirst(strtolower((string) $p->status));
        }
        $property_options[$p->property_id] = $label;
    }

    $user_options = [];
    foreach ($users as $u) {
        $user_options[$u->ID] = woodcrest_v23_real_name($u) . ' — ' . $u->user_email;
    }

    $access_form = woodcrest_fe_form_start('save_property_manager_access');
    $access_form .= woodcrest_fe_cascade_field('Property manager', 'user_id', '', true, $user_options, [], [], 'Search manager name or email');
    $access_form .= woodcrest_fe_cascade_field('Property', 'property_id', '', true, $property_options, [], [], 'Search / select property');
    $access_form .= woodcrest_fe_form_end('Assign manager');

    $coverage_id = absint($_GET['wc_manager_coverage_user'] ?? 0);
    $coverage_form = woodcrest_fe_form_start('save_manager_coverage');
    $coverage_form .= woodcrest_fe_cascade_field('Property manager', 'user_id', $coverage_id, true, $user_options, [], [], 'Search manager name or email');
    $coverage_form .= '<label class="wc-manager-coverage-option"><input type="checkbox" name="assign_current" value="1"> Assign to all current properties now</label>';
    $coverage_form .= '<label class="wc-manager-coverage-option"><input type="checkbox" name="auto_assign_future" value="1" ' . checked($coverage_id && get_user_meta($coverage_id, '_woodcrest_auto_assign_new_properties', true) === '1', true, false) . '> Automatically assign to newly created properties</label>';
    $coverage_form .= '<p class="woodcrest-utility-help">Turn off future assignments here at any time. Existing assignments remain until individually unassigned. For existing managers, use Edit automatic coverage below to load their settings.</p>';
    $coverage_form .= woodcrest_fe_form_end('Save manager coverage');

    $managers = [];
    foreach ($assignments as $a) {
        $manager_id = (int)$a->user_id;
        if (!isset($managers[$manager_id])) {
            $managers[$manager_id] = [
                'name' => $a->display_name ?: $a->user_login,
                'id' => $manager_id,
                'login' => $a->user_login,
                'email' => $a->user_email,
                'properties' => [],
            ];
        }
        $managers[$manager_id]['properties'][] = $a;
    }

    $manager_rows = '';
    foreach ($managers as $manager) {
        $property_rows = '';
        $property_count = 0;
        foreach ($manager['properties'] as $a) {
            $property_count++;
            $unassign_form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wc-manager-unassign-form">'
                . '<input type="hidden" name="action" value="woodcrest_frontend_management">'
                . '<input type="hidden" name="woodcrest_fe_action" value="unassign_property_manager_access">'
                . '<input type="hidden" name="access_id" value="' . (int) $a->access_id . '">'
                . wp_nonce_field('woodcrest_frontend_management', 'woodcrest_fe_nonce', true, false)
                . '<button type="submit" class="wc-manager-unassign-button" aria-label="Unassign ' . esc_attr($manager['name']) . ' from ' . esc_attr($a->property_name) . '">Unassign</button>'
                . '</form>';
            $property_rows .= '<tr data-property-id="' . (int) $a->property_id . '">'
                . '<td>' . esc_html($a->property_name) . '</td>'
                . '<td>' . esc_html((string) $a->assigned_tenants) . '</td>'
                . '<td>' . esc_html((string) $a->managed_suites) . '</td>'
                . '<td>' . esc_html((string) $a->occupied_suites) . '</td>'
                . '<td>' . esc_html((string) $a->vacant_suites) . '</td>'
                . '<td>' . $unassign_form . '</td></tr>';
        }
        $detail_id = 'wc-manager-properties-' . (int)$manager['id'];
        $detail_html = '<div class="wc-manager-assignment-subtable">'
            . '<p><a class="woodcrest-fe-link" href="' . esc_url(add_query_arg('wc_manager_coverage_user', (int)$manager['id'], woodcrest_fe_current_url())) . '">Edit automatic coverage</a></p>'
            . '<div class="woodcrest-table-wrap"><table class="woodcrest-table woodcrest-fe-table">'
            . '<thead><tr><th scope="col">Property</th><th scope="col">Tenants</th><th scope="col">Suites</th><th scope="col">Occupied</th><th scope="col">Vacant</th><th scope="col">Action</th></tr></thead>'
            . '<tbody>' . $property_rows . '</tbody></table></div>'
            . '</div>';
        $manager_rows .= '<tr class="wc-manager-assignment-row">'
            . '<td><button type="button" class="wc-manager-assignment-toggle" aria-expanded="false" aria-label="Assigned properties for ' . esc_attr($manager['name']) . '" aria-controls="' . esc_attr($detail_id) . '">'
            . '<span class="wc-manager-assignment-chevron" aria-hidden="true">&#9656;</span><span class="wc-manager-assignment-name">' . esc_html($manager['name']) . '</span></button></td>'
            . '<td class="wc-manager-assignment-email">' . esc_html($manager['email']) . '</td>'
            . '<td class="wc-manager-assignment-count">' . $property_count . ' propert' . ($property_count === 1 ? 'y' : 'ies') . '</td>'
            . '</tr>'
            . '<tr id="' . esc_attr($detail_id) . '" class="wc-manager-assignment-detail-row" hidden><td colspan="3">' . $detail_html . '</td></tr>'
            . '<tr class="wc-manager-group-spacer" aria-hidden="true"><td colspan="3"></td></tr>';
    }

    $html = woodcrest_fe_card('Assign Property Manager', $access_form);
    $html .= woodcrest_fe_card('Automatic Manager Coverage', $coverage_form, (bool)$coverage_id);
    $html .= '<section class="woodcrest-card woodcrest-card-wide woodcrest-pm-assignments-table"><div class="woodcrest-card-header"><h2>Property Manager Assignments</h2></div>';
    $html .= '<div class="woodcrest-table-wrap"><table class="woodcrest-table woodcrest-fe-table wc-manager-assignment-table">'
        . '<thead><tr><th scope="col">Manager Name</th><th scope="col">Email</th><th scope="col">Assigned Properties</th></tr></thead>'
        . '<tbody>' . ($manager_rows ?: '<tr><td colspan="3">No records found.</td></tr>') . '</tbody></table></div>';
    $html .= '</section>';

    return woodcrest_fe_admin_module_wrap($html);
}
add_shortcode('woodcrest_admin_property_managers', 'woodcrest_admin_property_managers_html');
