<?php
if (!defined('ABSPATH')) exit;

function wc_tn_tables() {
    global $wpdb;
    return [
        'users' => $wpdb->users,
        'property' => $wpdb->prefix . 'wc_property',
        'suite' => $wpdb->prefix . 'wc_suite',
        'suite_occupancy' => $wpdb->prefix . 'wc_suite_occupancy',
        'user_property_access' => $wpdb->prefix . 'wc_user_property_access',
        'reconciliation' => $wpdb->prefix . 'wc_nnn_reconciliation',
        'expense' => $wpdb->prefix . 'wc_nnn_expense',
        'allocation' => $wpdb->prefix . 'wc_nnn_allocation',
    ];
}

function wc_tn_table_exists($table) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
}

function wc_tn_column_exists($table, $column) {
    global $wpdb;
    if (!wc_tn_table_exists($table)) return false;
    return (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
}

function wc_tn_schema_issues() {
    $t = wc_tn_tables();
    $issues = [];
    foreach ($t as $key => $table) {
        if ($key === 'users') continue;
        if (!wc_tn_table_exists($table)) $issues[] = $table;
    }
    if ($issues) return $issues;

    $required = [
        [$t['reconciliation'], 'woodcrest_responsibility_percent'],
        [$t['allocation'], 'allocation_method'],
        [$t['allocation'], 'adjustment_amount'],
        [$t['allocation'], 'fixed_monthly_amount'],
        [$t['allocation'], 'override_reason'],
    ];
    foreach ($required as [$table, $column]) {
        if (!wc_tn_column_exists($table, $column)) $issues[] = $table . '.' . $column;
    }
    return $issues;
}

function wc_tn_schema_ready() {
    return count(wc_tn_schema_issues()) === 0;
}

function wc_tn_manager_property_ids($user_id = 0) {
    
    
    
    if (function_exists('woodcrest_fe_manager_property_ids')) {
        return woodcrest_fe_manager_property_ids($user_id);
    }

    global $wpdb;
    $t = wc_tn_tables();
    $user_id = absint($user_id) ?: get_current_user_id();
    if (!$user_id) return [];
    $today = current_time('Y-m-d');
    return array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT property_id
         FROM {$t['user_property_access']}
         WHERE user_id = %d
           AND access_scope = 'PROPERTY_MANAGER'
           AND (start_date IS NULL OR start_date <= %s)
           AND (end_date IS NULL OR end_date > %s)
         ORDER BY property_id",
        $user_id, $today, $today
    )));
}

function wc_tn_manager_scope_hidden_input() {
    return function_exists('woodcrest_fe_manager_scope_hidden_input')
        ? woodcrest_fe_manager_scope_hidden_input()
        : '';
}

function wc_tn_can_manage_property($property_id, $user_id = 0) {
    return in_array((int) $property_id, wc_tn_manager_property_ids($user_id), true);
}

function wc_tn_properties() {
    global $wpdb;
    $t = wc_tn_tables();
    $ids = wc_tn_manager_property_ids();
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '%d'));
    return $wpdb->get_results($wpdb->prepare(
        "SELECT property_id, property_name, address_line1, city, state, zip
         FROM {$t['property']}
         WHERE property_id IN ($ph)
         ORDER BY property_name",
        ...$ids
    ));
}

function wc_tn_property($property_id) {
    global $wpdb;
    $t = wc_tn_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT property_id, property_name, address_line1, city, state, zip
         FROM {$t['property']} WHERE property_id = %d LIMIT 1",
        $property_id
    ));
}

function wc_tn_valid_year($value, $fallback = null) {
    $year = (int) $value;
    if ($year >= 2000 && $year <= 2100) return $year;
    return $fallback ?: (int) current_time('Y');
}

function wc_tn_billing_year_from_expense($expense_year) {
    return (int) $expense_year + 1;
}

function wc_tn_context() {
    $properties = wc_tn_properties();
    $allowed_ids = array_map(static fn($p) => (int) $p->property_id, $properties);
    $default_property = $allowed_ids ? $allowed_ids[0] : 0;

    $property_id = isset($_GET['wc_tn_property']) ? absint($_GET['wc_tn_property']) : $default_property;
    if (!in_array($property_id, $allowed_ids, true)) $property_id = $default_property;

    $current_year = (int) current_time('Y');
    $default_expense_year = max(2000, min(2099, $current_year - 1));
    $expense_year = wc_tn_valid_year($_GET['wc_tn_expense_year'] ?? $default_expense_year, $default_expense_year);
    if ($expense_year > 2099) $expense_year = 2099;
    $billing_year = wc_tn_billing_year_from_expense($expense_year);

    return [
        'properties' => $properties,
        'property_id' => $property_id,
        'expense_year' => $expense_year,
        'billing_year' => $billing_year,
    ];
}

function wc_tn_get_reconciliation($property_id, $expense_year, $billing_year) {
    global $wpdb;
    $t = wc_tn_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['reconciliation']}
         WHERE property_id = %d AND expense_year = %d AND billing_year = %d
         LIMIT 1",
        $property_id, $expense_year, $billing_year
    ));
}

function wc_tn_get_reconciliation_by_id($id) {
    global $wpdb;
    $t = wc_tn_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['reconciliation']} WHERE nnn_reconciliation_id = %d LIMIT 1",
        $id
    ));
}

function wc_tn_get_expenses($reconciliation_id) {
    global $wpdb;
    $t = wc_tn_tables();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$t['expense']}
         WHERE nnn_reconciliation_id = %d
           AND expense_type IN ('CAM','INSURANCE','PROPERTY_TAX')",
        $reconciliation_id
    ));
    $out = [];
    foreach ($rows as $row) $out[$row->expense_type] = $row;
    return $out;
}

function wc_tn_property_suite_sqft($property_id) {
    global $wpdb;
    $t = wc_tn_tables();
    return (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(square_feet),0) FROM {$t['suite']} WHERE property_id = %d",
        $property_id
    ));
}

function wc_tn_eligible_occupancies($property_id, $billing_year) {
    global $wpdb;
    $t = wc_tn_tables();
    $year_start = sprintf('%04d-01-01', $billing_year);
    $year_end = sprintf('%04d-12-31', $billing_year);

    return $wpdb->get_results($wpdb->prepare(
        "SELECT so.occupancy_id, so.tenant_user_id, so.start_date, so.end_date,
                s.suite_id, s.suite_label, s.square_feet,
                u.display_name, u.user_login, u.user_email
         FROM {$t['suite_occupancy']} so
         JOIN {$t['suite']} s ON s.suite_id = so.suite_id
         JOIN {$t['users']} u ON u.ID = so.tenant_user_id
         WHERE s.property_id = %d
           AND so.start_date <= %s
           AND (so.end_date IS NULL OR so.end_date >= %s)
         ORDER BY s.suite_label, so.start_date, u.user_login",
        $property_id, $year_end, $year_start
    ));
}

function wc_tn_unique_occupied_sqft($occupancies) {
    $suites = [];
    foreach ((array) $occupancies as $row) {
        $suites[(int) $row->suite_id] = (float) $row->square_feet;
    }
    return array_sum($suites);
}

function wc_tn_upsert_expense($reconciliation_id, $type, $total_cost, $allocable_sqft, $notes = '') {
    global $wpdb;
    $t = wc_tn_tables();
    $rate = $allocable_sqft > 0 ? round($total_cost / $allocable_sqft, 6) : 0;
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT nnn_expense_id FROM {$t['expense']}
         WHERE nnn_reconciliation_id = %d AND expense_type = %s LIMIT 1",
        $reconciliation_id, $type
    ));
    $data = [
        'nnn_reconciliation_id' => $reconciliation_id,
        'expense_type' => $type,
        'total_cost' => round($total_cost, 2),
        'rate_per_sqft' => $rate,
        'notes' => $notes !== '' ? $notes : null,
    ];

    if ($existing) {
        $ok = $wpdb->update($t['expense'], $data, ['nnn_expense_id' => (int) $existing]);
        return $ok === false ? false : (int) $existing;
    }

    $ok = $wpdb->insert($t['expense'], $data);
    return $ok ? (int) $wpdb->insert_id : false;
}

function wc_tn_woodcrest_fixed_percent($rec) {
    if (!$rec || !isset($rec->woodcrest_responsibility_percent)) return 0.0;
    return max(0.0, min(100.0, (float) $rec->woodcrest_responsibility_percent));
}

function wc_tn_woodcrest_effective_share($rec, $occupancies = null) {
    if (!$rec || (float) $rec->total_allocable_sqft <= 0) return 1.0;
    if ($occupancies === null) {
        $occupancies = wc_tn_eligible_occupancies((int) $rec->property_id, (int) $rec->billing_year);
    }
    $occupied_sqft = wc_tn_unique_occupied_sqft($occupancies);
    $occupied_share = min(1.0, max(0.0, $occupied_sqft / (float) $rec->total_allocable_sqft));
    $owner_fixed = wc_tn_woodcrest_fixed_percent($rec) / 100;

    
    
    
    return max(0.0, min(1.0, 1 - ($occupied_share * (1 - $owner_fixed))));
}

function wc_tn_woodcrest_charge_summary($rec, $groups = []) {
    $occupancies = wc_tn_eligible_occupancies((int) $rec->property_id, (int) $rec->billing_year);
    $occupied_sqft = wc_tn_unique_occupied_sqft($occupancies);
    $total_sqft = max(0.0, (float) $rec->total_allocable_sqft);
    $vacant_sqft = max(0.0, $total_sqft - $occupied_sqft);
    $effective_share = wc_tn_woodcrest_effective_share($rec, $occupancies);
    $expenses = wc_tn_get_expenses((int) $rec->nnn_reconciliation_id);

    $tenant_monthly = ['CAM' => 0.0, 'INSURANCE' => 0.0, 'PROPERTY_TAX' => 0.0];
    foreach ((array) $groups as $group) {
        foreach ($tenant_monthly as $type => $_) {
            if (!empty($group['allocations'][$type])) {
                $tenant_monthly[$type] += (float) $group['allocations'][$type]->monthly_amount;
            }
        }
    }

    $woodcrest_monthly = [];
    foreach ($tenant_monthly as $type => $tenant_amount) {
        $expense_monthly = !empty($expenses[$type]) ? ((float) $expenses[$type]->total_cost / 12) : 0.0;
        
        $woodcrest_monthly[$type] = round($expense_monthly - $tenant_amount, 2);
    }

    return [
        'vacant_sqft' => $vacant_sqft,
        'fixed_percent' => wc_tn_woodcrest_fixed_percent($rec),
        'effective_share' => $effective_share,
        'monthly' => $woodcrest_monthly,
    ];
}

function wc_tn_recalculate_allocations($reconciliation_id) {
    global $wpdb;
    $t = wc_tn_tables();
    $rec = wc_tn_get_reconciliation_by_id($reconciliation_id);
    if (!$rec || (float) $rec->total_allocable_sqft <= 0) {
        return new WP_Error('invalid_reconciliation', 'Total allocable square footage must be greater than zero.');
    }

    $occupancies = wc_tn_eligible_occupancies((int) $rec->property_id, (int) $rec->billing_year);
    $occupied_sqft = wc_tn_unique_occupied_sqft($occupancies);
    if ($occupied_sqft > ((float) $rec->total_allocable_sqft + 0.01)) {
        return new WP_Error('allocable_too_small', 'Allocable square footage cannot be less than the occupied suite square footage represented in this billing year.');
    }

    $expenses = wc_tn_get_expenses($reconciliation_id);
    foreach (['CAM','INSURANCE','PROPERTY_TAX'] as $type) {
        if (empty($expenses[$type])) continue;
        $expense = $expenses[$type];
        $total = (float) $expense->total_cost;

        foreach ($occupancies as $occ) {
            $sqft = (float) $occ->square_feet;
            $owner_factor = 1 - (wc_tn_woodcrest_fixed_percent($rec) / 100);
            $pct = $sqft > 0 ? ($sqft / (float) $rec->total_allocable_sqft) * $owner_factor : 0;
            $base_monthly = round(($total * $pct) / 12, 2);

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t['allocation']}
                 WHERE nnn_expense_id = %d AND occupancy_id = %d LIMIT 1",
                $expense->nnn_expense_id, $occ->occupancy_id
            ));

            $method = $existing && $existing->allocation_method === 'FIXED' ? 'FIXED' : 'PRO_RATA';
            $adjustment = $existing ? (float) $existing->adjustment_amount : 0.0;
            $fixed = ($existing && $existing->fixed_monthly_amount !== null) ? (float) $existing->fixed_monthly_amount : null;
            $reason = $existing ? (string) $existing->override_reason : '';

            if ($method === 'FIXED') {
                $final_monthly = max(0, (float) ($fixed ?? 0));
                $adjustment = 0.0;
            } else {
                $final_monthly = max(0, round($base_monthly + $adjustment, 2));
                $fixed = null;
            }

            $data = [
                'nnn_expense_id' => (int) $expense->nnn_expense_id,
                'occupancy_id' => (int) $occ->occupancy_id,
                'square_feet_used' => round($sqft, 2),
                'allocation_percentage' => round($pct, 8),
                'allocation_method' => $method,
                'adjustment_amount' => round($adjustment, 2),
                'fixed_monthly_amount' => $fixed === null ? null : round($fixed, 2),
                'override_reason' => $reason !== '' ? $reason : null,
                'annual_amount' => round($final_monthly * 12, 2),
                'monthly_amount' => round($final_monthly, 2),
            ];

            $ok = $existing
                ? $wpdb->update($t['allocation'], $data, ['nnn_allocation_id' => (int) $existing->nnn_allocation_id])
                : $wpdb->insert($t['allocation'], $data);

            if ($ok === false) return new WP_Error('allocation_save_failed', 'An allocation could not be saved.');
        }
    }

    return true;
}

function wc_tn_get_grouped_allocations($reconciliation_id) {
    global $wpdb;
    $t = wc_tn_tables();
    $rec = wc_tn_get_reconciliation_by_id($reconciliation_id);
    if (!$rec) return [];

    $year_start = sprintf('%04d-01-01', (int) $rec->billing_year);
    $year_end = sprintf('%04d-12-31', (int) $rec->billing_year);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT so.occupancy_id, so.start_date, so.end_date,
                s.suite_id, s.suite_label, s.square_feet,
                u.display_name, u.user_login, u.user_email,
                e.expense_type, e.total_cost,
                a.nnn_allocation_id, a.square_feet_used, a.allocation_percentage,
                a.allocation_method, a.adjustment_amount, a.fixed_monthly_amount,
                a.override_reason, a.annual_amount, a.monthly_amount
         FROM {$t['suite_occupancy']} so
         JOIN {$t['suite']} s ON s.suite_id = so.suite_id
         JOIN {$t['users']} u ON u.ID = so.tenant_user_id
         LEFT JOIN {$t['expense']} e ON e.nnn_reconciliation_id = %d
                                      AND e.expense_type IN ('CAM','INSURANCE','PROPERTY_TAX')
         LEFT JOIN {$t['allocation']} a ON a.nnn_expense_id = e.nnn_expense_id
                                        AND a.occupancy_id = so.occupancy_id
         WHERE s.property_id = %d
           AND so.start_date <= %s
           AND (so.end_date IS NULL OR so.end_date >= %s)
         ORDER BY s.suite_label, so.start_date, u.user_login,
                  FIELD(e.expense_type,'CAM','INSURANCE','PROPERTY_TAX')",
        $reconciliation_id, $rec->property_id, $year_end, $year_start
    ));

    $grouped = [];
    foreach ($rows as $row) {
        $id = (int) $row->occupancy_id;
        if (!isset($grouped[$id])) {
            $grouped[$id] = [
                'occupancy_id' => $id,
                'tenant_user_id' => null,
                'display_name' => $row->display_name,
                'user_login' => $row->user_login,
                'user_email' => $row->user_email,
                'suite_id' => (int) $row->suite_id,
                'suite_label' => $row->suite_label,
                'square_feet' => (float) $row->square_feet,
                'start_date' => $row->start_date,
                'end_date' => $row->end_date,
                'allocations' => [],
            ];
        }
        if ($row->expense_type) $grouped[$id]['allocations'][$row->expense_type] = $row;
    }
    return array_values($grouped);
}

function wc_tn_allocation_belongs_to_reconciliation($allocation_id, $reconciliation_id) {
    global $wpdb;
    $t = wc_tn_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT a.*, e.expense_type, e.total_cost, e.nnn_reconciliation_id
         FROM {$t['allocation']} a
         JOIN {$t['expense']} e ON e.nnn_expense_id = a.nnn_expense_id
         WHERE a.nnn_allocation_id = %d AND e.nnn_reconciliation_id = %d LIMIT 1",
        $allocation_id, $reconciliation_id
    ));
}

function wc_tn_redirect($status, $message, $property_id, $expense_year, $billing_year) {
    $url = wp_get_referer();
    if (!$url) $url = home_url('/');
    $url = remove_query_arg(['wc_tn_status','wc_tn_message'], $url);
    $args = [
        'wc_tn_status' => $status,
        'wc_tn_message' => $message,
        'wc_tn_property' => (int) $property_id,
        'wc_tn_expense_year' => (int) $expense_year,
        'wc_tn_billing_year' => (int) $billing_year,
    ];
    $url = add_query_arg($args, $url);
    wp_safe_redirect($url);
    exit;
}

function wc_tn_money($value) {
    return number_format((float) $value, 2) . ' $';
}

function wc_tn_status_is_drafted($status) {
    return in_array(strtoupper((string) $status), ['DRAFTED','FINALIZED'], true);
}

function wc_tn_status_label($status) {
    return wc_tn_status_is_drafted($status) ? 'DRAFTED' : 'UNDRAFTED';
}

function wc_tn_percent($value) {
    $pct = (float) $value;
    if ($pct <= 1) $pct *= 100;
    return number_format($pct, 4) . '%';
}
