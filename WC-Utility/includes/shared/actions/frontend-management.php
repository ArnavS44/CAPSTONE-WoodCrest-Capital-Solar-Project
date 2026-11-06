<?php
add_action('admin_post_woodcrest_frontend_management', 'woodcrest_fe_handle_form');

function woodcrest_fe_unassign_manager_access($access_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $access_id = absint($access_id);
    if (!$access_id) return new WP_Error('invalid_assignment', 'Invalid manager assignment.');

    $today = current_time('Y-m-d');
    $assignment = $wpdb->get_row($wpdb->prepare(
        "SELECT access_id, user_id, property_id FROM {$t['user_property_access']}
         WHERE access_id = %d AND access_scope = 'PROPERTY_MANAGER' LIMIT 1",
        $access_id
    ));
    if (!$assignment) {
        return new WP_Error('assignment_not_found', 'This manager assignment no longer exists. Refresh the page and try again.');
    }

    $user_id = (int)$assignment->user_id;
    $property_id = (int)$assignment->property_id;
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$t['user_property_access']} SET end_date = %s
         WHERE user_id = %d AND property_id = %d AND access_scope = 'PROPERTY_MANAGER'
           AND (start_date IS NULL OR start_date <= %s)
           AND (end_date IS NULL OR end_date > %s)",
        $today, $user_id, $property_id, $today, $today
    ));
    if ($updated === false) {
        return new WP_Error('database_error', 'The database could not save the unassignment. Please try again.');
    }

    $remaining = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t['user_property_access']}
         WHERE user_id = %d AND property_id = %d AND access_scope = 'PROPERTY_MANAGER'
           AND (start_date IS NULL OR start_date <= %s)
           AND (end_date IS NULL OR end_date > %s)",
        $user_id, $property_id, $today, $today
    ));
    if ($remaining === null) {
        return new WP_Error('verification_failed', 'The database could not verify the unassignment. Refresh the page to check its status.');
    }
    if ((int)$remaining !== 0) {
        return new WP_Error('still_active', 'This property is still assigned in the database. No changes were shown; please retry or contact an administrator.');
    }

    return [
        'user_id' => $user_id,
        'property_id' => $property_id,
        'message' => $updated > 0 ? 'Property manager unassigned.' : 'Property manager already unassigned.',
    ];
}

add_action('wp_ajax_woodcrest_fe_unassign_manager_access', 'woodcrest_fe_ajax_unassign_manager_access');
function woodcrest_fe_ajax_unassign_manager_access() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Administrator access required.'], 403);
    }
    check_ajax_referer('woodcrest_fe_unassign_manager_access', 'nonce');
    $result = woodcrest_fe_unassign_manager_access($_POST['access_id'] ?? 0);
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
    wp_send_json_success($result);
}


function woodcrest_fe_handle_form() {
    if (!is_user_logged_in()) {
        auth_redirect();
    }
    if (empty($_POST['woodcrest_fe_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woodcrest_fe_nonce'])), 'woodcrest_frontend_management')) {
        wp_die('Security check failed.');
    }

    global $wpdb;
    $t = woodcrest_fe_tables();
    $action = sanitize_key($_POST['woodcrest_fe_action'] ?? '');
    $is_admin = current_user_can('manage_options');
    $effective_manager_id = woodcrest_fe_effective_manager_id();
    $has_manager_scope = $effective_manager_id && woodcrest_fe_is_property_manager($effective_manager_id);

    $admin_actions = ['delete_property', 'save_property', 'save_property_manager_access', 'end_property_manager_access', 'save_manager_coverage', 'unassign_property_manager_access'];
    if (in_array($action, $admin_actions, true) && !$is_admin) {
        wp_die('You do not have permission to perform this action.');
    }
    if (!in_array($action, $admin_actions, true) && !$has_manager_scope && !($is_admin && in_array($action, ['save_tenant_business_member', 'revoke_tenant_suite_access', 'grant_suite_account', 'remove_suite_account', 'update_suite_account_dates'], true))) {
        wp_die('This Property Manager action requires an active manager identity with assigned properties.');
    }

    $property_ids = woodcrest_fe_manager_property_ids($effective_manager_id);

     
     
    if ($action === 'update_suite_account_dates' && !empty($_POST['replacement_user_id'])) {
        $access_id = absint($_POST['access_id'] ?? 0);
        $scopes = $wpdb->prefix . 'wc_tenant_suite_access';
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT sa.*, so.tenant_user_id AS billing_user_id, so.start_date AS lease_start
             FROM {$scopes} sa JOIN {$t['suite_occupancy']} so
               ON so.suite_id=sa.suite_id AND so.business_id=sa.business_id
             WHERE sa.access_id=%d AND sa.status='ACTIVE'
             ORDER BY so.start_date DESC LIMIT 1", $access_id));
        if ($account && (int)$account->billing_user_id === (int)$account->user_id) {
            $end = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));
            $start = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
            if ($end !== current_time('Y-m-d')) woodcrest_fe_redirect('error','A billing contact transfer must take effect today. For a future move-out, end the suite occupancy when the date arrives.');
            if (woodcrest_fe_date_range_error($start, $end, true) || $start < $account->lease_start)
                woodcrest_fe_redirect('error','Check the occupancy dates before transferring the billing contact.');
            $property = woodcrest_fe_property_id_for_suite((int)$account->suite_id);
            if (!$is_admin && !woodcrest_fe_in_ids($property,$property_ids)) woodcrest_fe_redirect('error','You cannot manage this suite.');
            $_POST['suite_id'] = (int)$account->suite_id;
            $_POST['user_id'] = (int)$account->user_id;
            $_POST['rc101_member_start'] = $start;
            $action = 'remove_suite_account';
        }
    }

    switch ($action) {
        case 'delete_property':
            if (!$is_admin || strtoupper(trim((string)wp_unslash($_POST['delete_confirm'] ?? ''))) !== 'DELETE') {
                woodcrest_fe_redirect('error', 'Property deletion requires typing DELETE to confirm.');
            }
            $property_id = absint($_POST['property_id'] ?? 0);
            $result = woodcrest_fe_delete_property($property_id);
            if (is_wp_error($result)) woodcrest_fe_redirect('error', $result->get_error_message());
            woodcrest_fe_redirect('success', 'Property and its associated records were permanently deleted.');
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
                'status' => in_array(($_POST['status'] ?? ''), ['ACTIVE','INACTIVE'], true) ? $_POST['status'] : 'ACTIVE',
            ];
            if ($id) {
                $ok = $wpdb->update($t['property'], $data, ['property_id' => $id]);
            } else {
                $data['created_at'] = current_time('mysql');
                $ok = $wpdb->insert($t['property'], $data);
            }
            if ($ok !== false && !$id) {
                woodcrest_auto_assign_new_property((int)$wpdb->insert_id);
            }
            woodcrest_fe_redirect($ok === false ? 'error' : 'success', $ok === false ? 'Property could not be saved.' : 'Property saved.');
            break;

        case 'save_manager_coverage':
            $user_id = absint($_POST['user_id'] ?? 0);
            if (!$user_id || !get_userdata($user_id)) {
                woodcrest_fe_redirect('error', 'Choose a valid property manager account.');
            }
            $future = !empty($_POST['auto_assign_future']);
            if ($future) {
                update_user_meta($user_id, '_woodcrest_auto_assign_new_properties', '1');
            } else {
                delete_user_meta($user_id, '_woodcrest_auto_assign_new_properties');
            }
            $assigned = 0;
            $failed = 0;
            if (!empty($_POST['assign_current'])) {
                $all_properties = $wpdb->get_col("SELECT property_id FROM {$t['property']}");
                foreach ($all_properties as $property_id) {
                    if (woodcrest_auto_assign_manager_to_property($user_id, (int)$property_id)) {
                        $assigned++;
                    } else {
                        $failed++;
                    }
                }
            }
            woodcrest_fe_redirect($failed ? 'error' : 'success',
                $failed ? 'Coverage saved, but some property assignments could not be completed.'
                : 'Manager coverage saved. ' . ($assigned ? $assigned . ' current properties checked.' : 'Future coverage preference updated.'),
                ['wc_manager_coverage_user' => $user_id]);
            break;

        case 'unassign_property_manager_access':
            $result = woodcrest_fe_unassign_manager_access($_POST['access_id'] ?? 0);
            woodcrest_fe_redirect(is_wp_error($result) ? 'error' : 'success',
                is_wp_error($result) ? $result->get_error_message() : $result['message']);
            break;

        case 'save_property_manager_access':
            $id = absint($_POST['access_id'] ?? 0);

            
            
            $user_id = absint($_POST['user_id'] ?? 0);
            $property_id = absint($_POST['property_id'] ?? 0);
            $property_exists = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$t['property']} WHERE property_id = %d LIMIT 1",
                $property_id
            ));
            if (!$user_id || !$property_id || !get_userdata($user_id) || !$property_exists) {
                woodcrest_fe_redirect('error', 'Choose a valid user and property.');
            }
            $prior_access = $id ? $wpdb->get_row($wpdb->prepare(
                "SELECT start_date, end_date FROM {$t['user_property_access']} WHERE access_id = %d AND access_scope = 'PROPERTY_MANAGER'",
                $id
            )) : null;
            $data = [
                'user_id' => $user_id,
                'property_id' => $property_id,
                'access_scope' => 'PROPERTY_MANAGER',
                'start_date' => $prior_access->start_date ?? null,
                'end_date' => $prior_access->end_date ?? null,
            ];
            $date_error = woodcrest_fe_date_range_error($data['start_date'] ?: '', $data['end_date'] ?: '', false);
            if ($date_error) woodcrest_fe_redirect('error', $date_error);
            if ($id) {
                $ok = $wpdb->update($t['user_property_access'], $data, ['access_id' => $id]);
            } else {
                $existing = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT access_id FROM {$t['user_property_access']} WHERE user_id = %d AND property_id = %d AND access_scope = 'PROPERTY_MANAGER' LIMIT 1",
                    $user_id, $property_id
                ));
                if ($existing) {
                    $ok = $wpdb->update($t['user_property_access'], $data, ['access_id' => $existing]);
                } else {
                    $data['created_at'] = current_time('mysql');
                    $ok = $wpdb->insert($t['user_property_access'], $data);
                }
            }
            woodcrest_fe_redirect($ok === false ? 'error' : 'success', $ok === false ? 'Manager assignment could not be saved.' : 'Property manager assignment saved.');
            break;

        case 'end_property_manager_access':
            $id = absint($_POST['access_id'] ?? 0);
            $ok = $wpdb->update($t['user_property_access'], ['end_date' => current_time('Y-m-d')], ['access_id' => $id, 'access_scope' => 'PROPERTY_MANAGER']);
            woodcrest_fe_redirect($ok === false ? 'error' : 'success', $ok === false ? 'Assignment could not be ended.' : 'Manager assignment ended.');
            break;

        case 'update_suite_account_dates':
            if (!woodcrest_v24_suite_account_dates_ready()) woodcrest_fe_redirect('error','Suite account dates are not installed. Open WordPress Admin once.');
            $access_id = absint($_POST['access_id'] ?? 0);
            $start = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
            $end = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));
            if (woodcrest_fe_date_range_error($start, $end, true)) woodcrest_fe_redirect('error','Enter a valid start and end date.');
            $scopes = $wpdb->prefix . 'wc_tenant_suite_access';
            $account = $wpdb->get_row($wpdb->prepare(
                "SELECT sa.*, so.occupancy_id, so.tenant_user_id AS billing_user_id,
                        so.start_date AS lease_start, so.end_date AS lease_end
                 FROM {$scopes} sa JOIN {$t['suite_occupancy']} so
                   ON so.occupancy_id=(SELECT so2.occupancy_id FROM {$t['suite_occupancy']} so2
                                     WHERE so2.suite_id=sa.suite_id AND so2.business_id=sa.business_id
                                     ORDER BY so2.start_date DESC,so2.occupancy_id DESC LIMIT 1)
                 WHERE sa.access_id=%d AND sa.status='ACTIVE' LIMIT 1", $access_id));
            if (!$account) woodcrest_fe_redirect('error','Suite account not found or occupancy has already ended.');
            $suite_id = (int)$account->suite_id;
            $property_id = woodcrest_fe_property_id_for_suite($suite_id);
            if (!$property_id || (!$is_admin && !woodcrest_fe_in_ids($property_id,$property_ids))) woodcrest_fe_redirect('error','You cannot edit this suite.');
            if ($start < $account->lease_start || ($account->lease_end && (!$end || $end > $account->lease_end)))
                woodcrest_fe_redirect('error','Account dates must stay within the existing suite occupancy dates.');
            $today = current_time('Y-m-d');
            $is_primary = (int)$account->billing_user_id === (int)$account->user_id;
            $after_end = $end ? date('Y-m-d', strtotime($end . ' +1 day')) : $today;
            $other_members = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$scopes} sa WHERE sa.business_id=%d AND sa.suite_id=%d
                   AND sa.user_id<>%d AND sa.status='ACTIVE'
                   AND (sa.start_date IS NULL OR sa.start_date<=%s)
                   AND (sa.end_date IS NULL OR sa.end_date>=%s)",
                (int)$account->business_id,$suite_id,(int)$account->user_id,$after_end,$after_end));
            if ($is_primary && $end && $other_members)
                woodcrest_fe_redirect('error','Other tenant accounts still occupy this suite. Choose the new billing contact and end the current contact today.');
            if (!woodcrest_pm_table_exists(woodcrest_v24_access_history_table()))
                woodcrest_fe_redirect('error','Historical access storage is not ready. Open WordPress Admin once.');
            $wpdb->query('START TRANSACTION');
             
            if ($account->end_date && $account->end_date >= $today) {
                $wpdb->delete(woodcrest_v24_access_history_table(), [
                    'user_id'=>(int)$account->user_id,'suite_id'=>$suite_id,
                    'business_id'=>(int)$account->business_id,
                    'start_date'=>(string)$account->start_date,'end_date'=>(string)$account->end_date
                ]);
            }
            $done = $wpdb->update($scopes, ['start_date'=>$start,'end_date'=>$end ?: null,
                'status'=>($end && $end < $today ? 'INACTIVE' : 'ACTIVE')], ['access_id'=>$access_id]);
            if ($done === false) { $wpdb->query('ROLLBACK'); woodcrest_fe_redirect('error','Could not update the account dates.'); }
            if ($end) {
                if (!woodcrest_v24_archive_member_term((int)$account->user_id,(int)$account->business_id,
                    $suite_id,(int)$account->billing_user_id,$start,$end)) {
                    $wpdb->query('ROLLBACK'); woodcrest_fe_redirect('error','Historical occupancy could not be preserved.');
                }
            }
            if ($is_primary && $end) {
                 
                 
                $lease_saved=$wpdb->update($t['suite_occupancy'],['end_date'=>$end],['occupancy_id'=>(int)$account->occupancy_id]);
                if ($lease_saved === false) { $wpdb->query('ROLLBACK'); woodcrest_fe_redirect('error','Could not end suite occupancy.'); }
                $responsibilities=$wpdb->get_results($wpdb->prepare(
                    "SELECT responsibility_id,start_date,end_date FROM {$t['meter_responsibility']}
                     WHERE tenant_user_id=%d AND suite_id=%d AND responsible_party_type='TENANT' AND start_date<=%s
                       AND (end_date IS NULL OR end_date>%s)",
                    (int)$account->user_id,$suite_id,$end,$end));
                foreach ((array)$responsibilities as $mr) {
                    if ($wpdb->update($t['meter_responsibility'],['end_date'=>$end],['responsibility_id'=>(int)$mr->responsibility_id]) === false) {
                        $wpdb->query('ROLLBACK'); woodcrest_fe_redirect('error','Meter responsibility could not be ended.');
                    }
                }
            }
            $wpdb->query('COMMIT');
            if ($is_primary && $end) woodcrest_fe_sync_suite_status($suite_id);
            woodcrest_fe_redirect('success','Suite account occupancy dates saved. Tenant login and historical records are preserved.');
            break;

        case 'grant_suite_account':
        case 'remove_suite_account':
            if (!woodcrest_v23_suite_access_ready()) woodcrest_fe_redirect('error','Suite access migration is required.');
            $suite_id = absint($_POST['suite_id'] ?? 0);
            $user_id = absint($_POST['user_id'] ?? 0);
            $property_id = woodcrest_fe_property_id_for_suite($suite_id);
            if (!$suite_id || !$user_id || !$property_id || (!$is_admin && !woodcrest_fe_in_ids($property_id,$property_ids)))
                woodcrest_fe_redirect('error','You cannot manage this suite.');
            $user = get_userdata($user_id);
            if (!$user || user_can($user,'manage_options') || woodcrest_fe_is_property_manager($user_id))
                woodcrest_fe_redirect('error','Select an eligible tenant account.');
            if ($action === 'grant_suite_account' && !$is_admin && (int)get_user_meta($user_id,'_woodcrest_created_by_manager',true) !== $effective_manager_id)
                woodcrest_fe_redirect('error','You can grant access only to tenant accounts you created.');
            $today = current_time('Y-m-d');
            $occ = $wpdb->get_row($wpdb->prepare(
                "SELECT occupancy_id,business_id,tenant_user_id,start_date FROM {$t['suite_occupancy']}
                 WHERE suite_id=%d AND business_id IS NOT NULL AND start_date<=%s
                   AND (end_date IS NULL OR end_date >= %s)
                 ORDER BY start_date DESC LIMIT 1",$suite_id,$today,$today));
            if (!$occ) woodcrest_fe_redirect('error','Assign the main tenant to this suite before sharing access.');
            $business_id = (int)$occ->business_id;
            $members = $wpdb->prefix.'wc_tenant_business_member';
            $scope_table = $wpdb->prefix.'wc_tenant_suite_access';
            if ($action === 'remove_suite_account') {
                $is_primary = (int)$occ->tenant_user_id === $user_id;
                $replacement_id = absint($_POST['replacement_user_id'] ?? 0);
                if ($is_primary) {
                    $replacement = $replacement_id ? $wpdb->get_var($wpdb->prepare(
                        "SELECT sa.user_id FROM {$scope_table} sa JOIN {$members} bm ON bm.user_id=sa.user_id AND bm.business_id=sa.business_id AND bm.status='ACTIVE'
                         WHERE sa.user_id=%d AND sa.business_id=%d AND sa.suite_id=%d AND sa.status='ACTIVE'
                           AND (sa.start_date IS NULL OR sa.start_date<=CURRENT_DATE())
                           AND (sa.end_date IS NULL OR sa.end_date>CURRENT_DATE()) LIMIT 1",
                        $replacement_id,$business_id,$suite_id)) : 0;
                    if (!$replacement || $replacement_id === $user_id) woodcrest_fe_redirect('error','Add and select another active suite account as billing contact before removing this account.');
                     
                    $other_suite = (int)$wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$t['suite_occupancy']} WHERE tenant_user_id=%d AND suite_id<>%d AND start_date<=%s AND (end_date IS NULL OR end_date>=%s)",
                        $user_id,$suite_id,$today,$today));
                    if ($other_suite) woodcrest_fe_redirect('error','This billing contact has other active suites. Reassign those separately first.');
                }
                woodcrest_v24_install_access_history();
                $history = woodcrest_v24_access_history_table();
                if (!woodcrest_pm_table_exists($history)) woodcrest_fe_redirect('error','Suite history storage is unavailable. Open WordPress Admin once.');
                $scope = $wpdb->get_row($wpdb->prepare("SELECT access_id,created_at,start_date,end_date FROM {$scope_table} WHERE business_id=%d AND suite_id=%d AND user_id=%d AND status='ACTIVE'", $business_id,$suite_id,$user_id));
                if (!$scope) woodcrest_fe_redirect('error','Account does not have active suite access.');
                $grant_date = !empty($_POST['rc101_member_start']) ? sanitize_text_field(wp_unslash($_POST['rc101_member_start'])) : (string)($scope->start_date ?? substr((string)$scope->created_at,0,10));
                if ($occ->start_date < $grant_date) $grant_date = (string)$occ->start_date;
                $end_date = current_time('Y-m-d');
                $wpdb->query('START TRANSACTION');
                if ($is_primary) {
                     
                     
                    $previous_day = date('Y-m-d', strtotime($today . ' -1 day'));
                    $continuing = $wpdb->get_results($wpdb->prepare(
                        "SELECT user_id,start_date,created_at FROM {$scope_table}
                         WHERE business_id=%d AND suite_id=%d AND user_id<>%d AND status='ACTIVE'",
                        $business_id,$suite_id,$user_id));
                    foreach ((array)$continuing as $member) {
                        $from=(string)($member->start_date ?: substr((string)$member->created_at,0,10));
                        if ($from <= $previous_day && !woodcrest_v24_archive_member_term((int)$member->user_id,
                            $business_id,$suite_id,$user_id,$from,$previous_day)) {
                            $wpdb->query('ROLLBACK'); woodcrest_fe_redirect('error','Could not preserve earlier meter history. No accounts were changed.');
                        }
                    }
                    $business_table = $wpdb->prefix.'wc_tenant_business';
                    $business_updated = $wpdb->update($business_table,['primary_user_id'=>$replacement_id],['business_id'=>$business_id]);
                    $occupancy_updated = $wpdb->update($t['suite_occupancy'],['tenant_user_id'=>$replacement_id],
                        ['occupancy_id'=>(int)$occ->occupancy_id]);
                    if ($business_updated === false || !$occupancy_updated) {
                        $wpdb->query('ROLLBACK'); woodcrest_fe_redirect('error','Billing contact could not be reassigned; nothing was changed.');
                    }
                     
                    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
                    $responsibilities=$wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$t['meter_responsibility']} WHERE tenant_user_id=%d AND suite_id=%d AND responsible_party_type='TENANT'
                         AND start_date<=%s AND (end_date IS NULL OR end_date>=%s)", $user_id,$suite_id,$today,$today));
                    foreach ((array)$responsibilities as $mr) {
                        if ($mr->start_date === $today) {
                            $done=$wpdb->update($t['meter_responsibility'],['tenant_user_id'=>$replacement_id],['responsibility_id'=>(int)$mr->responsibility_id]);
                        } else {
                            $newrow=(array)$mr;
                            unset($newrow['responsibility_id']);
                            $newrow['tenant_user_id']=$replacement_id;
                            $newrow['start_date']=$today;
                            $newrow['created_at']=current_time('mysql');
                            $done=$wpdb->insert($t['meter_responsibility'],$newrow);
                            if ($done !== false) $done=$wpdb->update($t['meter_responsibility'],['end_date'=>$yesterday],['responsibility_id'=>(int)$mr->responsibility_id]);
                        }
                        if ($done === false) { $wpdb->query('ROLLBACK'); woodcrest_fe_redirect('error','Meter responsibility transfer failed; no changes were saved.'); }
                    }
                     
                     
                    $rates=$wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$t['rate_schedule']} WHERE tenant_user_id=%d AND start_date<=%s AND (end_date IS NULL OR end_date>=%s)",
                        $user_id,$today,$today));
                    foreach ((array)$rates as $rate) {
                        $exists=(int)$wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$t['rate_schedule']} WHERE tenant_user_id=%d AND utility_type=%s AND start_date<=%s AND (end_date IS NULL OR end_date>=%s)",
                            $replacement_id,$rate->utility_type,$today,$today));
                        if ($exists) continue;
                        $newrate=(array)$rate;
                        unset($newrate['rate_schedule_id']);
                        $newrate['tenant_user_id']=$replacement_id;
                        $newrate['start_date']=$today;
                        if ($wpdb->insert($t['rate_schedule'],$newrate) === false) {
                            $wpdb->query('ROLLBACK'); woodcrest_fe_redirect('error','Utility rate transfer failed; no changes were saved.');
                        }
                    }
                }
                $archived = $wpdb->insert($history,['business_id'=>$business_id,'suite_id'=>$suite_id,'user_id'=>$user_id,
                    'billing_user_id'=>(int)$occ->tenant_user_id,'start_date'=>$grant_date,'end_date'=>$end_date]);
                $ok = $archived !== false ? $wpdb->update($scope_table,['status'=>'INACTIVE','start_date'=>$grant_date,'end_date'=>$end_date],['access_id'=>(int)$scope->access_id]) : false;
                if ($ok === false) { $wpdb->query('ROLLBACK'); woodcrest_fe_redirect('error','Could not preserve historical access; no changes were saved.'); }
                $wpdb->query('COMMIT');
                woodcrest_fe_redirect('success','Suite access removed; historical invoices remain available.');
            }
            $existing_biz = (int)$wpdb->get_var($wpdb->prepare("SELECT business_id FROM {$members} WHERE user_id=%d",$user_id));
             
            if ($existing_biz && $existing_biz !== $business_id)
                woodcrest_fe_redirect('error','This account already has a separate billing assignment. Use a different additional account for this suite.');
            $wpdb->query('START TRANSACTION');
            if ($existing_biz) {
                $member_ok=$wpdb->update($members,['status'=>'ACTIVE'],['user_id'=>$user_id,'business_id'=>$business_id]);
            } else {
                $member_ok=$wpdb->insert($members,['user_id'=>$user_id,'business_id'=>$business_id,'status'=>'ACTIVE','created_at'=>current_time('mysql')]);
            }
            $existing_scope=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT access_id FROM {$scope_table} WHERE business_id=%d AND user_id=%d AND suite_id=%d",$business_id,$user_id,$suite_id));
            $scope_ok = $existing_scope ? $wpdb->update($scope_table,['status'=>'ACTIVE','created_at'=>current_time('mysql'),'start_date'=>$today,'end_date'=>null],['access_id'=>$existing_scope])
                : $wpdb->insert($scope_table,['business_id'=>$business_id,'user_id'=>$user_id,'suite_id'=>$suite_id,'status'=>'ACTIVE','created_at'=>current_time('mysql'),'start_date'=>$today,'end_date'=>null]);
            if ($member_ok === false || $scope_ok === false) {
                $wpdb->query('ROLLBACK');
                woodcrest_fe_redirect('error','Could not give this account suite access.');
            }
            $wpdb->query('COMMIT');
            woodcrest_fe_redirect('success','Account added to suite; billing remains unchanged.');
            break;

        case 'revoke_tenant_suite_access':
            if (!woodcrest_v23_suite_access_ready()) woodcrest_fe_redirect('error', 'Run the v2.3 Suite Access migration first.');
            $business_id = absint($_POST['business_id'] ?? 0);
            $suite_id = absint($_POST['suite_id'] ?? 0);
            $property_id = absint($_POST['property_id'] ?? 0);
            $user_id = absint($_POST['user_id'] ?? 0);
            $business_table = $wpdb->prefix . 'wc_tenant_business';
            $scope_table = $wpdb->prefix . 'wc_tenant_suite_access';
            $primary_id = (int)$wpdb->get_var($wpdb->prepare("SELECT primary_user_id FROM {$business_table} WHERE business_id = %d", $business_id));
            if (!$primary_id || $primary_id === $user_id) woodcrest_fe_redirect('error', 'The billing contact cannot be revoked from this suite.');
            if (!$property_id || $property_id !== woodcrest_fe_property_id_for_suite($suite_id)
                || !woodcrest_v23_business_occupies_suite($business_id, $suite_id)) {
                woodcrest_fe_redirect('error', 'Business and suite do not match.');
            }
            if (!$is_admin && !woodcrest_fe_in_ids($property_id, $property_ids)) {
                woodcrest_fe_redirect('error', 'Suite is outside your assigned properties.');
            }
            woodcrest_v24_install_access_history();
            $history = woodcrest_v24_access_history_table();
            if (!woodcrest_pm_table_exists($history)) woodcrest_fe_redirect('error','Suite history storage is unavailable.');
            $scope = $wpdb->get_row($wpdb->prepare("SELECT access_id,created_at FROM {$scope_table} WHERE business_id=%d AND suite_id=%d AND user_id=%d AND status='ACTIVE'", $business_id,$suite_id,$user_id));
            if (!$scope) woodcrest_fe_redirect('error','Suite access is not active.');
            $wpdb->query('START TRANSACTION');
            $saved = $wpdb->insert($history, ['user_id'=>$user_id,'business_id'=>$business_id,'suite_id'=>$suite_id,'billing_user_id'=>$primary_id,
                'start_date'=>substr((string)$scope->created_at,0,10),'end_date'=>current_time('Y-m-d')]);
            $ok = $saved !== false ? $wpdb->update($scope_table, ['status'=>'INACTIVE'], ['access_id'=>(int)$scope->access_id]) : false;
            if ($ok === false) { $wpdb->query('ROLLBACK'); woodcrest_fe_redirect('error','Could not preserve historical access.'); }
            $wpdb->query('COMMIT');
            woodcrest_fe_redirect('success', 'Suite access revoked.');
            break;

        case 'save_tenant_business_member':
            if (!woodcrest_v23_suite_access_ready()) woodcrest_fe_redirect('error', 'Run the v2.3 Suite Access migration first.');
            $business_id = absint($_POST['business_id'] ?? 0);
            $user_id = absint($_POST['user_id'] ?? 0);
            $suite_id = absint($_POST['suite_id'] ?? 0);
            $property_id = absint($_POST['property_id'] ?? 0);
            $business_table = $wpdb->prefix . 'wc_tenant_business';
            $member_table = $wpdb->prefix . 'wc_tenant_business_member';
            $scope_table = $wpdb->prefix . 'wc_tenant_suite_access';
            $business = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$business_table} WHERE business_id = %d", $business_id));
            $user = get_userdata($user_id);
            if (!$business || !$user || user_can($user, 'manage_options') || woodcrest_fe_is_property_manager($user_id)) {
                woodcrest_fe_redirect('error', 'Choose a valid tenant account.');
            }
            if (!$property_id || $property_id !== woodcrest_fe_property_id_for_suite($suite_id)
                || !woodcrest_v23_business_occupies_suite($business_id, $suite_id)) {
                woodcrest_fe_redirect('error', 'The selected suite is not occupied by that business at the selected property.');
            }
            if (!$is_admin && (!woodcrest_fe_in_ids($property_id, $property_ids)
                || (int)get_user_meta($user_id, '_woodcrest_created_by_manager', true) !== $effective_manager_id)) {
                woodcrest_fe_redirect('error', 'Managers may grant access only for assigned properties and accounts they created.');
            }
            $other = (int)$wpdb->get_var($wpdb->prepare("SELECT business_id FROM {$member_table} WHERE user_id = %d", $user_id));
            if ($other && $other !== $business_id) woodcrest_fe_redirect('error', 'This user belongs to another tenant business.');
            $wpdb->query('START TRANSACTION');
            if (!$other) {
                $saved = $wpdb->insert($member_table, ['business_id'=>$business_id,'user_id'=>$user_id,'status'=>'ACTIVE','created_at'=>current_time('mysql')]);
            } else {
                $saved = $wpdb->update($member_table, ['status'=>'ACTIVE'], ['business_id'=>$business_id,'user_id'=>$user_id]);
            }
            $existing = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT access_id FROM {$scope_table} WHERE business_id = %d AND user_id = %d AND suite_id = %d",
                $business_id, $user_id, $suite_id
            ));
            if ($existing) {
                $scoped = $wpdb->update($scope_table, ['status'=>'ACTIVE'], ['access_id'=>$existing]);
            } else {
                $scoped = $wpdb->insert($scope_table, [
                    'business_id'=>$business_id,'user_id'=>$user_id,'suite_id'=>$suite_id,
                    'status'=>'ACTIVE','created_at'=>current_time('mysql')
                ]);
            }
            if ($saved === false || $scoped === false) {
                $wpdb->query('ROLLBACK');
                woodcrest_fe_redirect('error', 'Could not save suite-level representative access.');
            }
            $name = $is_admin ? trim(sanitize_text_field(wp_unslash($_POST['business_name'] ?? ''))) : '';
            if ($name !== '' && $wpdb->update($business_table, ['business_name'=>$name], ['business_id'=>$business_id]) === false) {
                $wpdb->query('ROLLBACK');
                woodcrest_fe_redirect('error', 'Could not update business name.');
            }
            $wpdb->query('COMMIT');
            woodcrest_fe_redirect('success', 'Access granted to this suite only. No billing records changed.');
            break;

        case 'create_tenant':
            $username = sanitize_user(wp_unslash($_POST['user_login'] ?? ''), true);
            $email = sanitize_email(wp_unslash($_POST['user_email'] ?? ''));
            $display_name = sanitize_text_field(wp_unslash($_POST['display_name'] ?? ''));
            $password = (string) ($_POST['user_pass'] ?? '');
            if (!$username || !$email || !$display_name || strlen($password) < 8) woodcrest_fe_redirect('error', 'Tenant needs username, email, name, and a password of at least 8 characters.');
            $user_id = wp_insert_user([
                'user_login' => $username,
                'user_email' => $email,
                'display_name' => $display_name,
                'user_pass' => $password,
                'role' => 'subscriber',
            ]);
            if (is_wp_error($user_id)) woodcrest_fe_redirect('error', $user_id->get_error_message());
            update_user_meta($user_id, '_woodcrest_created_by_manager', $effective_manager_id);
            if (woodcrest_v23_ready()) update_user_meta($user_id, '_woodcrest_tenant_business_name', sanitize_text_field(wp_unslash($_POST['business_name'] ?? '')));
            woodcrest_fe_redirect('success', 'Tenant account created. You can now assign the tenant to one of your suites.', ['wc_new_tenant_id' => (int)$user_id]);
            break;

        case 'save_suite':
            $id = absint($_POST['suite_id'] ?? 0);
            $property_id = absint($_POST['property_id'] ?? 0);
            if (!woodcrest_fe_manager_can_manage_record_property($property_id)) woodcrest_fe_redirect('error', 'That property is not assigned to you.');
            if ($id) {
                $existing_property = woodcrest_fe_property_id_for_suite($id);
                if (!woodcrest_fe_manager_can_manage_record_property($existing_property)) woodcrest_fe_redirect('error', 'That suite is outside your assigned properties.');
            }
            $data = [
                'property_id' => $property_id,
                'suite_label' => sanitize_text_field(wp_unslash($_POST['suite_label'] ?? '')),
                'square_feet' => (float) ($_POST['square_feet'] ?? 0),
            ];
            if ($id) {
                $ok = $wpdb->update($t['suite'], $data, ['suite_id' => $id]);
                $saved_suite_id = $id;
            } else {
                $data['status'] = 'VACANT';
                $data['created_at'] = current_time('mysql');
                $ok = $wpdb->insert($t['suite'], $data);
                $saved_suite_id = (int)$wpdb->insert_id;
            }
            if ($ok !== false && $saved_suite_id) woodcrest_fe_sync_suite_status($saved_suite_id);
            woodcrest_fe_redirect($ok === false ? 'error' : 'success', $ok === false ? 'Suite could not be saved.' : 'Suite saved.');
            break;

        case 'save_occupancy':
            if (!woodcrest_v23_ready()) woodcrest_fe_redirect('error', 'Complete the Woodcrest v2.3 database migration before changing occupancy.');
            $id = absint($_POST['occupancy_id'] ?? 0);
            $suite_id = absint($_POST['suite_id'] ?? 0);
            $tenant_id = absint($_POST['tenant_user_id'] ?? 0);
            if (woodcrest_v23_ready()) $tenant_id = woodcrest_v23_primary_tenant_id($tenant_id);
            $selected_property_id = absint($_POST['property_id'] ?? 0);
            $property_id = woodcrest_fe_property_id_for_suite($suite_id);
            if ($selected_property_id && $selected_property_id !== $property_id) woodcrest_fe_redirect('error', 'The selected suite does not belong to the selected property.');
            if (!woodcrest_fe_manager_can_manage_record_property($property_id)) woodcrest_fe_redirect('error', 'That suite is outside your assigned properties.');
            if (!$tenant_id || !get_userdata($tenant_id)) woodcrest_fe_redirect('error', 'Select a valid WordPress user.');
            if (!woodcrest_fe_tenant_is_scoped($tenant_id, $effective_manager_id)) {
                woodcrest_fe_redirect('error', "That tenant is outside this property manager's assigned properties. Create the tenant from the manager portal or assign them through an administrator workflow.");
            }
            if ($id) {
                $old_suite = (int) $wpdb->get_var($wpdb->prepare("SELECT suite_id FROM {$t['suite_occupancy']} WHERE occupancy_id = %d", $id));
                if (!woodcrest_fe_manager_can_manage_record_property(woodcrest_fe_property_id_for_suite($old_suite))) woodcrest_fe_redirect('error', 'That occupancy is outside your assigned properties.');
            }
            $business_id = woodcrest_v23_ready() ? woodcrest_v23_ensure_business($tenant_id, (string)get_user_meta($tenant_id, '_woodcrest_tenant_business_name', true)) : 0;
            if (woodcrest_v23_ready() && !$business_id) woodcrest_fe_redirect('error', 'Could not establish tenant business relationship.');
            $data = [
                'suite_id' => $suite_id,
                'tenant_user_id' => $tenant_id,
                'business_id' => $business_id ?: null,
                'start_date' => sanitize_text_field(wp_unslash($_POST['start_date'] ?? '')),
                'end_date' => sanitize_text_field(wp_unslash($_POST['end_date'] ?? '')) ?: null,
            ];
            $date_error = woodcrest_fe_date_range_error($data['start_date'], $data['end_date'] ?: '', true);
            if ($date_error) woodcrest_fe_redirect('error', $date_error);
            if (woodcrest_fe_ranges_overlap(
                $t['suite_occupancy'],
                'occupancy_id',
                $id,
                'suite_id = %d',
                [$suite_id],
                $data['start_date'],
                $data['end_date']
            )) {
                woodcrest_fe_redirect('error', 'That suite already has a tenant assignment overlapping those dates.');
            }
            $wpdb->query('START TRANSACTION');
            if ($id) {
                $ok = $wpdb->update($t['suite_occupancy'], $data, ['occupancy_id' => $id]);
            } else {
                $data['created_at'] = current_time('mysql');
                $ok = $wpdb->insert($t['suite_occupancy'], $data);
            }
            $grant_ok = $ok !== false && (!woodcrest_v23_suite_access_ready() || woodcrest_v23_grant_primary_suite($business_id, $suite_id));
            if ($grant_ok && !empty($data['end_date'])) {
                $grant_ok = woodcrest_v24_sync_lease_membership_dates($business_id,$suite_id,$data['end_date'],$tenant_id);
            }
            if (!$grant_ok) {
                $wpdb->query('ROLLBACK');
                woodcrest_fe_redirect('error', 'Tenant assignment was not saved. Check the occupancy and billing-contact suite permission.');
            }
            $wpdb->query('COMMIT');
            if (!empty($old_suite) && (int)$old_suite !== $suite_id) woodcrest_fe_sync_suite_status($old_suite);
            woodcrest_fe_sync_suite_status($suite_id);
            woodcrest_fe_redirect('success', 'Tenant-to-suite assignment saved.');
            break;

        case 'save_rate':
            $id = absint($_POST['rate_schedule_id'] ?? 0);
            $tenant_id = absint($_POST['tenant_user_id'] ?? 0);
            if (woodcrest_v23_ready()) $tenant_id = woodcrest_v23_primary_tenant_id($tenant_id);
            if (!woodcrest_fe_tenant_is_scoped($tenant_id)) woodcrest_fe_redirect('error', 'That tenant is outside your assigned properties.');
            if ($id) {
                $existing_tenant = (int) $wpdb->get_var($wpdb->prepare("SELECT tenant_user_id FROM {$t['rate_schedule']} WHERE rate_schedule_id = %d", $id));
                if (!woodcrest_fe_tenant_is_scoped($existing_tenant)) woodcrest_fe_redirect('error', 'That rate is outside your assigned tenants.');
            }
            $utility_type = sanitize_text_field(wp_unslash($_POST['utility_type'] ?? ''));
            if (!in_array($utility_type, ['ELECTRIC','WATER'], true)) $utility_type = 'ELECTRIC';
            $data = [
                'tenant_user_id' => $tenant_id,
                'utility_type' => $utility_type,
                'base_rate_per_unit' => (float) ($_POST['base_rate_per_unit'] ?? 0),
                'start_date' => sanitize_text_field(wp_unslash($_POST['start_date'] ?? '')),
                'end_date' => sanitize_text_field(wp_unslash($_POST['end_date'] ?? '')) ?: null,
                'notes' => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')) ?: null,
            ];
            $date_error = woodcrest_fe_date_range_error($data['start_date'], $data['end_date'] ?: '', true);
            if ($date_error) woodcrest_fe_redirect('error', $date_error);
            if (woodcrest_fe_ranges_overlap(
                $t['rate_schedule'],
                'rate_schedule_id',
                $id,
                'tenant_user_id = %d AND utility_type = %s',
                [$tenant_id, $utility_type],
                $data['start_date'],
                $data['end_date']
            )) {
                woodcrest_fe_redirect('error', 'That tenant already has an overlapping ' . strtolower($utility_type) . ' utility rate.');
            }
            $ok = $id ? $wpdb->update($t['rate_schedule'], $data, ['rate_schedule_id' => $id]) : $wpdb->insert($t['rate_schedule'], $data);
            woodcrest_fe_redirect($ok === false ? 'error' : 'success', $ok === false ? 'Utility rate could not be saved.' : 'Utility rate saved.');
            break;

        case 'save_utility_account':
            $id = absint($_POST['utility_account_id'] ?? 0);
            $property_id = absint($_POST['property_id'] ?? 0);
            if (!woodcrest_fe_manager_can_manage_record_property($property_id)) woodcrest_fe_redirect('error', 'That property is not assigned to you.');
            if ($id && !woodcrest_fe_manager_can_manage_record_property(woodcrest_fe_property_id_for_account($id))) woodcrest_fe_redirect('error', 'That utility account is outside your assigned properties.');
            $utility_type = sanitize_text_field(wp_unslash($_POST['utility_type'] ?? ''));
            if (!in_array($utility_type, ['ELECTRIC','WATER'], true)) $utility_type = 'ELECTRIC';
            $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));
            if (!in_array($status, ['ACTIVE','INACTIVE'], true)) $status = 'ACTIVE';
            if ($id) {
                $mismatch = (bool)$wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$t['meter']}
                     WHERE utility_account_id = %d
                       AND (property_id <> %d OR utility_type <> %s)
                     LIMIT 1",
                    $id, $property_id, $utility_type
                ));
                if ($mismatch) {
                    woodcrest_fe_redirect('error', 'This utility account is already used by a meter. Its property and utility type must continue to match those meters.');
                }
            }
            $data = [
                'property_id' => $property_id,
                'utility_type' => $utility_type,
                'account_number' => sanitize_text_field(wp_unslash($_POST['account_number'] ?? '')),
                'esi_id' => sanitize_text_field(wp_unslash($_POST['esi_id'] ?? '')) ?: null,
                'utility_company' => sanitize_text_field(wp_unslash($_POST['utility_company'] ?? '')),
                'service_address' => sanitize_text_field(wp_unslash($_POST['service_address'] ?? '')) ?: null,
                'account_description' => sanitize_text_field(wp_unslash($_POST['account_description'] ?? '')) ?: null,
                'status' => $status,
            ];
            if ($id) $ok = $wpdb->update($t['utility_account'], $data, ['utility_account_id' => $id]);
            else { $data['created_at'] = current_time('mysql'); $ok = $wpdb->insert($t['utility_account'], $data); }
            woodcrest_fe_redirect($ok === false ? 'error' : 'success', $ok === false ? 'Utility account could not be saved.' : 'Utility account saved.');
            break;

        case 'save_meter':
            $id = absint($_POST['meter_id'] ?? 0);
            $property_id = absint($_POST['property_id'] ?? 0);
            $account_id = absint($_POST['utility_account_id'] ?? 0);
            if (!woodcrest_fe_manager_can_manage_record_property($property_id)) woodcrest_fe_redirect('error', 'That property is not assigned to you.');
            if ($id && !woodcrest_fe_manager_can_manage_record_property(woodcrest_fe_property_id_for_meter($id))) woodcrest_fe_redirect('error', 'That meter is outside your assigned properties.');
            $meter_type = sanitize_text_field(wp_unslash($_POST['meter_type'] ?? ''));
            if (!in_array($meter_type, ['MASTER','SUBMETER'], true)) $meter_type = 'SUBMETER';
            $account = woodcrest_fe_account_info($account_id);
            if (!$account || (int)$account->property_id !== $property_id) woodcrest_fe_redirect('error', 'The utility account must belong to the selected property.');
            $utility_type = strtoupper((string)$account->utility_type);
            if ($id) {
                $existing_meter = woodcrest_fe_meter_info($id);
                $has_responsibilities = (bool)$wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$t['meter_responsibility']} WHERE meter_id = %d LIMIT 1",
                    $id
                ));
                if ($has_responsibilities && $existing_meter && (
                    (int)$existing_meter->property_id !== $property_id ||
                    (int)$existing_meter->utility_account_id !== $account_id ||
                    strtoupper((string)$existing_meter->utility_type) !== $utility_type
                )) {
                    woodcrest_fe_redirect('error', 'End or update this meter\'s suite associations before changing its property, account, or utility type.');
                }
            }
            $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));
            if (!in_array($status, ['ACTIVE','INACTIVE'], true)) $status = 'ACTIVE';
            $data = [
                'property_id' => $property_id,
                'utility_account_id' => $account_id,
                'meter_number' => sanitize_text_field(wp_unslash($_POST['meter_number'] ?? '')),
                'meter_type' => $meter_type,
                'utility_type' => $utility_type,
                'meter_size' => sanitize_text_field(wp_unslash($_POST['meter_size'] ?? '')) ?: null,
                'meter_name' => sanitize_text_field(wp_unslash($_POST['meter_name'] ?? '')) ?: null,
                'serial_number' => sanitize_text_field(wp_unslash($_POST['serial_number'] ?? '')) ?: null,
                'status' => $status,
            ];
            if ($id) $ok = $wpdb->update($t['meter'], $data, ['meter_id' => $id]);
            else { $data['created_at'] = current_time('mysql'); $ok = $wpdb->insert($t['meter'], $data); }
            woodcrest_fe_redirect($ok === false ? 'error' : 'success', $ok === false ? 'Meter could not be saved.' : 'Meter saved.');
            break;

        case 'save_meter_responsibility':
            $id = absint($_POST['responsibility_id'] ?? 0);
            $meter_id = absint($_POST['meter_id'] ?? 0);
            $account_id = absint($_POST['utility_account_id'] ?? 0);
            $suite_id = absint($_POST['suite_id'] ?? 0);
            $tenant_id = 0;
            $selected_property_id = absint($_POST['property_id'] ?? 0);
            $meter = woodcrest_fe_meter_info($meter_id);
            $property_id = $meter ? (int)$meter->property_id : 0;
            if (!$meter || !woodcrest_fe_manager_can_manage_record_property($property_id)) woodcrest_fe_redirect('error', 'That meter is outside your assigned properties.');
            if ($selected_property_id && $selected_property_id !== $property_id) woodcrest_fe_redirect('error', 'The selected meter does not belong to the selected property.');
            $account_id = (int)$meter->utility_account_id;
            $account = woodcrest_fe_account_info($account_id);
            if (!$account || (int)$account->property_id !== $property_id || strtoupper((string)$account->utility_type) !== strtoupper((string)$meter->utility_type)) {
                woodcrest_fe_redirect('error', 'The meter and utility account are not compatible.');
            }
            if ($suite_id && woodcrest_fe_property_id_for_suite($suite_id) !== $property_id) woodcrest_fe_redirect('error', 'The suite must belong to the meter property.');
            if ($id) {
                $old_meter = (int) $wpdb->get_var($wpdb->prepare("SELECT meter_id FROM {$t['meter_responsibility']} WHERE responsibility_id = %d", $id));
                if (!woodcrest_fe_manager_can_manage_record_property(woodcrest_fe_property_id_for_meter($old_meter))) woodcrest_fe_redirect('error', 'That meter assignment is outside your assigned properties.');
            }
            $party = sanitize_text_field(wp_unslash($_POST['responsible_party_type'] ?? ''));
            if (!in_array($party, ['TENANT','VACANT_WOODCREST','CAM'], true)) $party = 'TENANT';
            $start_date = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
            $end_date = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));
            if ($party === 'TENANT') {
                if (!$suite_id) woodcrest_fe_redirect('error', 'Choose a suite for tenant meter allocation.');
                $occupancy = $wpdb->get_row($wpdb->prepare(
                    "SELECT tenant_user_id, start_date, end_date FROM {$t['suite_occupancy']} WHERE suite_id=%d AND start_date<=%s AND (end_date IS NULL OR end_date>=%s) ORDER BY start_date DESC, occupancy_id DESC LIMIT 1",
                    $suite_id, $start_date, $end_date ?: $start_date));
                $tenant_id = $occupancy ? (int)$occupancy->tenant_user_id : 0;
                if (!$tenant_id) woodcrest_fe_redirect('error', 'The suite needs a billing occupancy covering the meter assignment dates.');
                if (!$end_date && !empty($occupancy->end_date)) $end_date = $occupancy->end_date;
            } else {
                $tenant_id = 0;
                $suite_id = 0;
            }
            $allocation = sanitize_text_field(wp_unslash($_POST['allocation_method'] ?? ''));
            if (!in_array($allocation, ['ACTUAL','PRO_RATA_SF','FIXED_PERCENT'], true)) $allocation = 'ACTUAL';
            $billing = sanitize_text_field(wp_unslash($_POST['billing_model'] ?? ''));
            if (!in_array($billing, ['PASS_THROUGH','RESALE'], true)) $billing = 'PASS_THROUGH';
            $data = [
                'meter_id' => $meter_id,
                'utility_account_id' => $account_id,
                'tenant_user_id' => $tenant_id ?: null,
                'suite_id' => $suite_id ?: null,
                'description' => sanitize_text_field(wp_unslash($_POST['description'] ?? '')) ?: null,
                'responsible_party_type' => $party,
                'allocation_method' => $allocation,
                'fixed_percent' => ($_POST['fixed_percent'] ?? '') === '' ? null : (float) $_POST['fixed_percent'],
                'billing_model' => $billing,
                'monthly_charge_amount' => ($_POST['monthly_charge_amount'] ?? '') === '' ? null : (float) $_POST['monthly_charge_amount'],
                'start_date' => $start_date,
                'end_date' => $end_date ?: null,
            ];
            $date_error = woodcrest_fe_date_range_error($data['start_date'], $data['end_date'] ?: '', true);
            if ($date_error) woodcrest_fe_redirect('error', $date_error);
            if ($party === 'TENANT' && !woodcrest_fe_tenant_occupancy_covers_range($tenant_id, $suite_id, $data['start_date'], $data['end_date'])) {
                woodcrest_fe_redirect('error', 'The selected tenant must occupy that suite for the full meter-assignment period.');
            }
            if ($suite_id && woodcrest_fe_ranges_overlap(
                $t['meter_responsibility'],
                'responsibility_id',
                $id,
                'meter_id = %d AND suite_id = %d',
                [$meter_id, $suite_id],
                $data['start_date'],
                $data['end_date']
            )) {
                woodcrest_fe_redirect('error', 'That meter already has an overlapping assignment for this suite.');
            }
            if (!$suite_id && woodcrest_fe_ranges_overlap(
                $t['meter_responsibility'], 'responsibility_id', $id,
                'meter_id = %d AND suite_id IS NULL AND responsible_party_type = %s',
                [$meter_id, $party], $data['start_date'], $data['end_date'])) {
                woodcrest_fe_redirect('error', 'This property meter already has an overlapping assignment.');
            }
            if ($id) $ok = $wpdb->update($t['meter_responsibility'], $data, ['responsibility_id' => $id]);
            else { $data['created_at'] = current_time('mysql'); $ok = $wpdb->insert($t['meter_responsibility'], $data); }
            woodcrest_fe_redirect($ok === false ? 'error' : 'success', $ok === false ? 'Meter-to-suite assignment could not be saved.' : 'Meter-to-suite assignment saved.');
            break;
    }

    woodcrest_fe_redirect('error', 'Unknown action.');
}




