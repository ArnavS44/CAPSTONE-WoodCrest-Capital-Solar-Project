<?php
if (!defined('ABSPATH')) exit;

function woodcrest_fe_property_delete_ids($table, $pk, $where_column, $ids) {
    global $wpdb;
    if (!woodcrest_pm_table_exists($table)) return [];
    if (!woodcrest_pm_column_exists($table, $pk) || !woodcrest_pm_column_exists($table, $where_column)) {
        throw new RuntimeException('Unsupported property-related table layout: ' . $table);
    }
    $ids = array_values(array_unique(array_filter(array_map('absint', (array)$ids))));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $result = $wpdb->get_col($wpdb->prepare("SELECT `{$pk}` FROM `{$table}` WHERE `{$where_column}` IN ({$placeholders})", ...$ids));
    if ($result === null || $wpdb->last_error) throw new RuntimeException('Could not read related records in ' . $table);
    return array_values(array_unique(array_filter(array_map('intval', (array)$result))));
}

function woodcrest_fe_property_delete_target_ids($table, $pk, $kind, $ids) {
    global $wpdb;
    if (!woodcrest_pm_table_exists($table)) return [];
    foreach ([$pk, 'target_kind', 'target_id'] as $column) {
        if (!woodcrest_pm_column_exists($table, $column)) throw new RuntimeException('Unsupported import posting/audit schema: ' . $table);
    }
    $ids = array_values(array_unique(array_filter(array_map('absint', (array)$ids))));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '%d'));
    $records = $wpdb->get_col($wpdb->prepare("SELECT `{$pk}` FROM `{$table}` WHERE target_kind=%s AND target_id IN ({$ph})", ...array_merge([$kind], $ids)));
    if ($wpdb->last_error) throw new RuntimeException('Could not inspect linked import posting/audit records.');
    return array_values(array_unique(array_map('intval', (array)$records)));
}

function woodcrest_fe_property_delete_plan($property_id) {
    global $wpdb;
    $property_id = absint($property_id);
    $prefix = $wpdb->prefix;
    $property = $prefix . 'wc_property';
    if (!$property_id || !woodcrest_pm_table_exists($property)) return new WP_Error('missing_property', 'Property not found.');
    $name = $wpdb->get_var($wpdb->prepare("SELECT property_name FROM {$property} WHERE property_id=%d", $property_id));
    if ($name === null) return new WP_Error('missing_property', 'Property not found.');
    try {
        $definitions = [
            'property'=>['property','property_id'],
            'suite'=>['suite','suite_id'],
            'user_property_access'=>['user_property_access','access_id'],
            'utility_account'=>['utility_account','utility_account_id'],
            'meter'=>['meter','meter_id'],
            'meter_responsibility'=>['meter_responsibility','responsibility_id'],
            'utility_reading'=>['utility_reading','reading_id'],
            'utility_monthly_cost'=>['utility_monthly_cost','monthly_cost_id'],
            'invoice'=>['invoice','invoice_id'],
            'invoice_line_item'=>['invoice_line_item','line_item_id'],
            'payment'=>['payment','payment_id'],
            'master_utility_bill'=>['master_utility_bill','bill_id'],
            'meter_hierarchy'=>['meter_hierarchy','hierarchy_id'],
            'suite_occupancy'=>['suite_occupancy','occupancy_id'],
            'tenant_suite_access'=>['tenant_suite_access','access_id'],
            'tenant_suite_access_history'=>['tenant_suite_access_history','history_id'],
            'nnn_reconciliation'=>['nnn_reconciliation','nnn_reconciliation_id'],
            'nnn_expense'=>['nnn_expense','nnn_expense_id'],
            'nnn_allocation'=>['nnn_allocation','nnn_allocation_id'],
            'supplier_invoice'=>['supplier_invoice','supplier_invoice_id'],
            'supplier_invoice_charge'=>['supplier_invoice_charge','supplier_charge_id'],
            'invoice_posting'=>['invoice_posting','posting_id'],
            'invoice_verified_map'=>['invoice_verified_map','mapping_id'],
            'utility_change_log'=>['utility_change_log','audit_id'],
            'import_external_ref'=>['import_external_ref','ref_id'],
        ];
        foreach ($definitions as $key=>$def) {
            $table = $prefix . 'wc_' . $def[0];
            if (!woodcrest_pm_table_exists($table) || woodcrest_pm_column_exists($table, $def[1])) continue;
            $index = $wpdb->get_row("SHOW INDEX FROM `{$table}` WHERE Key_name='PRIMARY' ORDER BY Seq_in_index LIMIT 1");
            if (!$index || empty($index->Column_name)) throw new RuntimeException('No supported primary key on ' . $table);
            $definitions[$key][1] = (string)$index->Column_name;
        }
        $get = static function($key) use ($prefix, $definitions) { return $prefix . 'wc_' . $definitions[$key][0]; };
        $ids = [];
        foreach ($definitions as $key => $def) $ids[$key] = [];
        $select = static function($key, $column, $related) use (&$ids, $get, $definitions) {
            $ids[$key] = array_values(array_unique(array_merge($ids[$key], woodcrest_fe_property_delete_ids($get($key), $definitions[$key][1], $column, $related))));
        };
        $ids['property'] = [$property_id];
        foreach (['suite','user_property_access','utility_account','meter','invoice','master_utility_bill','nnn_reconciliation','supplier_invoice','invoice_verified_map'] as $key) {
            $select($key, 'property_id', [$property_id]);
        }
        $select('meter', 'utility_account_id', $ids['utility_account']);
        $select('meter_responsibility', 'meter_id', $ids['meter']);
        $select('meter_responsibility', 'suite_id', $ids['suite']);
        $select('meter_responsibility', 'utility_account_id', $ids['utility_account']);
        $select('utility_reading', 'meter_id', $ids['meter']);
        $select('utility_monthly_cost', 'utility_account_id', $ids['utility_account']);
        $select('suite_occupancy', 'suite_id', $ids['suite']);
        $select('tenant_suite_access', 'suite_id', $ids['suite']);
        $select('tenant_suite_access_history', 'suite_id', $ids['suite']);
        $select('invoice', 'suite_id', $ids['suite']);
        $select('invoice_line_item', 'invoice_id', $ids['invoice']);
        $select('payment', 'invoice_id', $ids['invoice']);
        $select('nnn_expense', 'nnn_reconciliation_id', $ids['nnn_reconciliation']);
        $select('nnn_allocation', 'nnn_expense_id', $ids['nnn_expense']);
        $select('supplier_invoice_charge', 'supplier_invoice_id', $ids['supplier_invoice']);
        $select('invoice_posting', 'supplier_invoice_id', $ids['supplier_invoice']);
        foreach (['reading'=>'utility_reading', 'cost'=>'utility_monthly_cost'] as $kind=>$target) {
            foreach (['invoice_posting','utility_change_log'] as $source) {
                $linked = woodcrest_fe_property_delete_target_ids($get($source), $definitions[$source][1], $kind, $ids[$target]);
                $ids[$source] = array_values(array_unique(array_merge($ids[$source], $linked)));
            }
        }
        $select('utility_change_log', 'supplier_invoice_id', $ids['supplier_invoice']);
        $select('meter_hierarchy', 'upstream_meter_id', $ids['meter']);
        $select('meter_hierarchy', 'downstream_meter_id', $ids['meter']);

        $refs = $get('import_external_ref');
        if (woodcrest_pm_table_exists($refs)) {
            foreach (['properties'=>'property','suites'=>'suite','manager_access'=>'user_property_access',
                'utility_accounts'=>'utility_account','meters'=>'meter','meter_responsibilities'=>'meter_responsibility',
                'readings'=>'utility_reading','utility_costs'=>'utility_monthly_cost','occupancies'=>'suite_occupancy',
                'representative_access'=>'tenant_suite_access','nnn_reconciliations'=>'nnn_reconciliation',
                'nnn_expenses'=>'nnn_expense'] as $type=>$key) {
                $related = $ids[$key];
                if (!$related) continue;
                $ph = implode(',', array_fill(0, count($related), '%d'));
                $result = $wpdb->get_col($wpdb->prepare("SELECT ref_id FROM {$refs} WHERE entity_type=%s AND record_id IN ({$ph})", ...array_merge([$type], $related)));
                if ($wpdb->last_error) throw new RuntimeException('Could not inspect import references.');
                $ids['import_external_ref'] = array_merge($ids['import_external_ref'], array_map('intval', (array)$result));
            }
            $ids['import_external_ref'] = array_values(array_unique($ids['import_external_ref']));
        }
        $known = array_fill_keys(array_map($get, array_keys($definitions)), true);
        $known[$prefix . 'wc_import_batch'] = true;
        $known[$prefix . 'wc_rate_schedule'] = true;
        $known[$prefix . 'wc_tenant_business'] = true;
        $known[$prefix . 'wc_tenant_business_member'] = true;
        $all = (array)$wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix . 'wc_') . '%'));
        if ($wpdb->last_error) throw new RuntimeException('Could not inspect all WoodCrest database tables.');
        foreach ($all as $table) {
            if (isset($known[$table]) || !woodcrest_pm_column_exists($table, 'property_id')) continue;
            $found = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE property_id=%d", $property_id));
            if ($found) throw new RuntimeException('Deletion stopped: an additional property-linked table needs review (' . $table . ').');
        }
        foreach ($ids as $key=>$values) {
            if (!$values) continue;
            $table = $get($key);
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)));
            if (!$status || strtoupper((string)($status->Engine ?? '')) !== 'INNODB') {
                throw new RuntimeException('Deletion requires transactional InnoDB tables; please check ' . $table . '.');
            }
        }
        return ['property_name'=>(string)$name,'property_id'=>$property_id,'ids'=>$ids,'tables'=>$definitions];
    } catch (RuntimeException $ex) {
        return new WP_Error('delete_preflight', $ex->getMessage());
    }
}

function woodcrest_fe_delete_property($property_id) {
    global $wpdb;
    if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Administrator permissions required.');
    $plan = woodcrest_fe_property_delete_plan($property_id);
    if (is_wp_error($plan)) return $plan;
    $order = ['import_external_ref','utility_change_log','invoice_posting','supplier_invoice_charge',
        'invoice_verified_map','payment','invoice_line_item','nnn_allocation','nnn_expense',
        'nnn_reconciliation','tenant_suite_access_history','tenant_suite_access','suite_occupancy',
        'meter_hierarchy','meter_responsibility','utility_reading','utility_monthly_cost',
        'master_utility_bill','invoice','supplier_invoice','meter','utility_account',
        'user_property_access','suite','property'];
    if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('transaction_failed', 'Could not start the database transaction.');
    foreach ($order as $key) {
        $ids = $plan['ids'][$key];
        if (!$ids) continue;
        $table = $wpdb->prefix . 'wc_' . $plan['tables'][$key][0];
        $pk = $plan['tables'][$key][1];
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        $result = $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE `{$pk}` IN ({$ph})", ...$ids));
        if ($result === false || $result !== count($ids)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('delete_failed', 'The database could not completely delete ' . $key . '. No changes were committed.');
        }
    }
    if ($wpdb->query('COMMIT') === false) return new WP_Error('commit_failed', 'Database commit failed. Verify the property data against a backup.');
    do_action('woodcrest_fe_property_deleted', (int)$property_id, $plan, get_current_user_id());
    return $plan;
}
