<?php


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
    <div class="woodcrest-page wc-role-admin">
        <section class="woodcrest-card woodcrest-card-wide wc-standalone-table-tools">
            <div class="woodcrest-card-header">
                <h2>All System Users</h2>
            </div>

            <div class="woodcrest-table-wrap">
                <table class="woodcrest-table woodcrest-fe-table" id="woodcrest-admin-demo-table">
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
                            if ($u->is_manager) $roles[] = '<span class="woodcrest-badge wc-demo-role-manager">MANAGER</span>';
                            if ($u->is_tenant) $roles[] = '<span class="woodcrest-badge wc-demo-role-tenant">TENANT</span>';
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
                                            <a class="woodcrest-action-btn woodcrest-admin-portal-btn wc-demo-manager-btn" href="<?php echo esc_url(add_query_arg(['wc_view_as'=>'manager','wc_view_user'=>(int)$u->ID,'_wpnonce'=>wp_create_nonce('woodcrest_view_manager_' . (int)$u->ID)], woodcrest_fe_role_home_url('manager'))); ?>">Manager Portal</a>
                                        <?php endif; ?>
                                        <?php if ($u->is_tenant): ?>
                                            <a class="woodcrest-action-btn secondary woodcrest-admin-portal-btn wc-demo-tenant-btn" href="<?php echo esc_url(add_query_arg(['wc_view_as'=>'tenant','wc_view_user'=>(int)$u->ID,'_wpnonce'=>wp_create_nonce('woodcrest_view_tenant_' . (int)$u->ID)], woodcrest_fe_role_home_url('tenant'))); ?>">Tenant Portal</a>
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

    <?php
    return ob_get_clean();
}

add_shortcode('woodcrest_admin_demo_portal', 'woodcrest_admin_demo_portal_html');
