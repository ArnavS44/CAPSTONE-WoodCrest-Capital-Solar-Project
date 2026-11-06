<?php
if (!defined('ABSPATH')) exit;

add_action('admin_post_wc_tn_save_draft', 'wc_tn_handle_save_draft');
add_action('admin_post_wc_tn_save_allocations', 'wc_tn_handle_save_allocations');
add_action('admin_post_wc_tn_finalize', 'wc_tn_handle_finalize');
add_action('admin_post_wc_tn_reopen', 'wc_tn_handle_reopen');

function wc_tn_action_context() {
    $property_id = absint($_POST['property_id'] ?? 0);
    $expense_year = (int) ($_POST['expense_year'] ?? 0);
    if ($expense_year < 2000 || $expense_year > 2099) {
        wp_die('Choose a valid Expense Year.', 400);
    }
    
    $billing_year = wc_tn_billing_year_from_expense($expense_year);
    if (!is_user_logged_in() || !$property_id || !wc_tn_can_manage_property($property_id)) {
        wp_die('You do not have permission to manage Triple Net data for this property.', 403);
    }
    return [$property_id, $expense_year, $billing_year];
}

function wc_tn_handle_save_draft() {
    check_admin_referer('wc_tn_save_draft');
    [$property_id, $expense_year, $billing_year] = wc_tn_action_context();

    
    
    $allocable_sqft = wc_tn_property_suite_sqft($property_id);
    if ($allocable_sqft <= 0) {
        wc_tn_redirect('error', 'This property does not have any suite square footage yet.', $property_id, $expense_year, $billing_year);
    }

    $occupancies = wc_tn_eligible_occupancies($property_id, $billing_year);
    $occupied_sqft = wc_tn_unique_occupied_sqft($occupancies);
    if ($occupied_sqft > ($allocable_sqft + 0.01)) {
        wc_tn_redirect('error', 'Occupied suite square footage is greater than the property suite total. Check the suite records.', $property_id, $expense_year, $billing_year);
    }

    $costs = [
        'CAM' => max(0, (float) ($_POST['cam_total'] ?? 0)),
        'INSURANCE' => max(0, (float) ($_POST['insurance_total'] ?? 0)),
        'PROPERTY_TAX' => max(0, (float) ($_POST['property_tax_total'] ?? 0)),
    ];
    $notes = [
        'CAM' => sanitize_textarea_field($_POST['cam_notes'] ?? ''),
        'INSURANCE' => sanitize_textarea_field($_POST['insurance_notes'] ?? ''),
        'PROPERTY_TAX' => sanitize_textarea_field($_POST['property_tax_notes'] ?? ''),
    ];

    $woodcrest_enabled = !empty($_POST['woodcrest_fixed_enabled']);
    $woodcrest_percent = 0.0;
    if ($woodcrest_enabled) {
        $raw_owner_percent = isset($_POST['woodcrest_responsibility_percent'])
            ? trim((string) wp_unslash($_POST['woodcrest_responsibility_percent']))
            : '';
        if ($raw_owner_percent === '' || !is_numeric($raw_owner_percent)) {
            wc_tn_redirect('error', 'Enter a WoodCrest responsibility percentage or turn the option off.', $property_id, $expense_year, $billing_year);
        }
        $woodcrest_percent = (float) $raw_owner_percent;
        if ($woodcrest_percent <= 0 || $woodcrest_percent > 100) {
            wc_tn_redirect('error', 'WoodCrest responsibility must be greater than 0% and no more than 100%.', $property_id, $expense_year, $billing_year);
        }
    }

    global $wpdb;
    $t = wc_tn_tables();
    $wpdb->query('START TRANSACTION');
    try {
        $rec = wc_tn_get_reconciliation($property_id, $expense_year, $billing_year);
        if ($rec && wc_tn_status_is_drafted($rec->status)) throw new Exception('This Triple Net year is DRAFTED. Return it to UNDRAFTED before making changes.');

        $rec_data = [
            'total_allocable_sqft' => round($allocable_sqft, 2),
            'woodcrest_responsibility_percent' => round($woodcrest_percent, 4),
            'status' => 'UNDRAFTED',
            'finalized_at' => null,
            'finalized_by_user_id' => null,
        ];

        if ($rec) {
            $ok = $wpdb->update($t['reconciliation'], $rec_data, ['nnn_reconciliation_id' => (int) $rec->nnn_reconciliation_id]);
            if ($ok === false) throw new Exception('Could not update the Triple Net setup.');
            $rec_id = (int) $rec->nnn_reconciliation_id;
        } else {
            $rec_data['property_id'] = $property_id;
            $rec_data['expense_year'] = $expense_year;
            $rec_data['billing_year'] = $billing_year;
            $ok = $wpdb->insert($t['reconciliation'], $rec_data);
            if (!$ok) throw new Exception('Could not create the Triple Net setup.');
            $rec_id = (int) $wpdb->insert_id;
        }

        foreach ($costs as $type => $total) {
            if (wc_tn_upsert_expense($rec_id, $type, $total, $allocable_sqft, $notes[$type]) === false) {
                throw new Exception('Could not save one of the Triple Net expenses.');
            }
        }

        $recalc = wc_tn_recalculate_allocations($rec_id);
        if (is_wp_error($recalc)) throw new Exception($recalc->get_error_message());

        $wpdb->query('COMMIT');
        wc_tn_redirect('success', 'Triple Net costs saved and tenant charges recalculated.', $property_id, $expense_year, $billing_year);
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        wc_tn_redirect('error', $e->getMessage(), $property_id, $expense_year, $billing_year);
    }
}

function wc_tn_handle_save_allocations() {
    check_admin_referer('wc_tn_save_allocations');
    [$property_id, $expense_year, $billing_year] = wc_tn_action_context();
    $rec = wc_tn_get_reconciliation($property_id, $expense_year, $billing_year);
    if (!$rec) wc_tn_redirect('error', 'Save the Triple Net costs before editing tenant charges.', $property_id, $expense_year, $billing_year);
    if (wc_tn_status_is_drafted($rec->status)) wc_tn_redirect('error', 'Return this Triple Net year to UNDRAFTED before editing tenant charges.', $property_id, $expense_year, $billing_year);

    $posted = isset($_POST['alloc']) && is_array($_POST['alloc']) ? wp_unslash($_POST['alloc']) : [];
    if (!$posted) wc_tn_redirect('error', 'No tenant charge changes were submitted.', $property_id, $expense_year, $billing_year);

    global $wpdb;
    $t = wc_tn_tables();
    $wpdb->query('START TRANSACTION');
    try {
        foreach ($posted as $allocation_id_raw => $values) {
            $allocation_id = absint($allocation_id_raw);
            if (!$allocation_id || !is_array($values)) continue;
            $allocation = wc_tn_allocation_belongs_to_reconciliation($allocation_id, (int) $rec->nnn_reconciliation_id);
            if (!$allocation) throw new Exception('One of the submitted allocation records is invalid.');

            $method = strtoupper(sanitize_text_field($values['method'] ?? 'PRO_RATA'));
            if (!in_array($method, ['PRO_RATA','FIXED'], true)) $method = 'PRO_RATA';
            $adjustment = (float) ($values['adjustment_amount'] ?? 0);
            $fixed_raw = isset($values['fixed_monthly_amount']) ? trim((string) $values['fixed_monthly_amount']) : '';
            $fixed = $fixed_raw === '' ? null : (float) $fixed_raw;
            $reason = sanitize_text_field($values['override_reason'] ?? '');

            $pct = (float) $allocation->allocation_percentage;
            $base_monthly = round(((float) $allocation->total_cost * $pct) / 12, 2);

            if ($method === 'FIXED') {
                if ($fixed === null || $fixed < 0) throw new Exception('Every Fixed Amount selection needs a non-negative monthly amount.');
                if ($reason === '') throw new Exception('Enter a reason for each fixed tenant charge.');
                $final_monthly = round($fixed, 2);
                $adjustment = 0.0;
            } else {
                $fixed = null;
                $final_monthly = round($base_monthly + $adjustment, 2);
                if ($final_monthly < 0) throw new Exception('A tenant adjustment cannot reduce a monthly charge below $0.00.');
                if (abs($adjustment) > 0.00001 && $reason === '') throw new Exception('Enter a reason for each manual tenant adjustment.');
                if (abs($adjustment) <= 0.00001) $reason = '';
            }

            $ok = $wpdb->update($t['allocation'], [
                'allocation_method' => $method,
                'adjustment_amount' => round($adjustment, 2),
                'fixed_monthly_amount' => $fixed === null ? null : round($fixed, 2),
                'override_reason' => $reason !== '' ? $reason : null,
                'monthly_amount' => round($final_monthly, 2),
                'annual_amount' => round($final_monthly * 12, 2),
            ], ['nnn_allocation_id' => $allocation_id]);
            if ($ok === false) throw new Exception('Could not save one of the tenant charge adjustments.');
        }
        $wpdb->query('COMMIT');
        wc_tn_redirect('success', 'Tenant charge changes saved.', $property_id, $expense_year, $billing_year);
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        wc_tn_redirect('error', $e->getMessage(), $property_id, $expense_year, $billing_year);
    }
}

function wc_tn_handle_finalize() {
    check_admin_referer('wc_tn_finalize');
    [$property_id, $expense_year, $billing_year] = wc_tn_action_context();
    global $wpdb;
    $t = wc_tn_tables();
    $rec = wc_tn_get_reconciliation($property_id, $expense_year, $billing_year);
    if (!$rec) wc_tn_redirect('error', 'Save the Triple Net record before marking it DRAFTED.', $property_id, $expense_year, $billing_year);
    if (wc_tn_status_is_drafted($rec->status)) wc_tn_redirect('success', 'This Triple Net year is already DRAFTED.', $property_id, $expense_year, $billing_year);

    $wpdb->query('START TRANSACTION');
    try {
        $recalc = wc_tn_recalculate_allocations((int) $rec->nnn_reconciliation_id);
        if (is_wp_error($recalc)) throw new Exception($recalc->get_error_message());
        $expenses = wc_tn_get_expenses((int) $rec->nnn_reconciliation_id);
        foreach (['CAM','INSURANCE','PROPERTY_TAX'] as $type) {
            if (empty($expenses[$type])) throw new Exception('CAM, Insurance, and Property Tax must be saved before finalizing.');
        }
        $ok = $wpdb->update($t['reconciliation'], [
            'status' => 'DRAFTED',
            'finalized_at' => current_time('mysql'),
            'finalized_by_user_id' => get_current_user_id(),
        ], ['nnn_reconciliation_id' => (int) $rec->nnn_reconciliation_id]);
        if ($ok === false) throw new Exception('Could not mark the Triple Net year DRAFTED.');
        $wpdb->query('COMMIT');
        wc_tn_redirect('success', 'Triple Net marked DRAFTED for invoices.', $property_id, $expense_year, $billing_year);
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        wc_tn_redirect('error', $e->getMessage(), $property_id, $expense_year, $billing_year);
    }
}

function wc_tn_handle_reopen() {
    check_admin_referer('wc_tn_reopen');
    [$property_id, $expense_year, $billing_year] = wc_tn_action_context();
    global $wpdb;
    $t = wc_tn_tables();
    $rec = wc_tn_get_reconciliation($property_id, $expense_year, $billing_year);
    if (!$rec) wc_tn_redirect('error', 'Triple Net record not found.', $property_id, $expense_year, $billing_year);

    $ok = $wpdb->update($t['reconciliation'], [
        'status' => 'UNDRAFTED',
        'finalized_at' => null,
        'finalized_by_user_id' => null,
    ], ['nnn_reconciliation_id' => (int) $rec->nnn_reconciliation_id]);

    if ($ok === false) wc_tn_redirect('error', 'Could not return the Triple Net year to UNDRAFTED.', $property_id, $expense_year, $billing_year);
    wc_tn_redirect('success', 'Triple Net returned to UNDRAFTED.', $property_id, $expense_year, $billing_year);
}
