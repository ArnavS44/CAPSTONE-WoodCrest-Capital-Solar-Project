<?php
if (!defined('ABSPATH')) exit;

function woodcrest_v25_tables() {
    global $wpdb;
    return [
        'posting' => $wpdb->prefix . 'wc_invoice_posting',
        'mapping' => $wpdb->prefix . 'wc_invoice_verified_map',
        'audit' => $wpdb->prefix . 'wc_utility_change_log',
    ];
}

function woodcrest_v25_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = woodcrest_v25_tables();
    $collate = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$t['posting']} (
        posting_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        supplier_invoice_id bigint(20) unsigned NOT NULL,
        target_kind varchar(16) NOT NULL,
        target_id bigint(20) unsigned NOT NULL,
        original_values longtext NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (posting_id),
        UNIQUE KEY kind_target (target_kind,target_id),
        KEY invoice_lookup (supplier_invoice_id)
    ) {$collate};");
    dbDelta("CREATE TABLE {$t['mapping']} (
        mapping_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        property_id bigint(20) unsigned NOT NULL,
        utility_type varchar(16) NOT NULL,
        provider_account varchar(100) NOT NULL,
        provider_meter varchar(100) NOT NULL DEFAULT '',
        utility_account_id bigint(20) unsigned NOT NULL,
        meter_id bigint(20) unsigned NOT NULL DEFAULT 0,
        verified_by bigint(20) unsigned NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (mapping_id),
        UNIQUE KEY mapping_source (property_id,utility_type,provider_account,provider_meter)
    ) {$collate};");
    dbDelta("CREATE TABLE {$t['audit']} (
        audit_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        target_kind varchar(16) NOT NULL,
        target_id bigint(20) unsigned NOT NULL,
        supplier_invoice_id bigint(20) unsigned DEFAULT NULL,
        actor_id bigint(20) unsigned NOT NULL,
        action_name varchar(24) NOT NULL,
        before_json longtext DEFAULT NULL,
        after_json longtext NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (audit_id),
        KEY target_lookup (target_kind,target_id),
        KEY invoice_lookup (supplier_invoice_id)
    ) {$collate};");
    if (woodcrest_fe_table_exists($t['posting']) && woodcrest_fe_table_exists($t['mapping']) && woodcrest_fe_table_exists($t['audit'])) {
        update_option('woodcrest_v25_schema', '1');
    }
}
register_activation_hook(WCPM_DIR . 'wc-utility.php', 'woodcrest_v25_install');
add_action('admin_init', function () {
    if (current_user_can('manage_options') && get_option('woodcrest_v25_schema') !== '1' && woodcrest_v23_ready()) woodcrest_v25_install();
});

function woodcrest_v25_audit($kind, $id, $action, $before, $after, $invoice_id = null) {
    global $wpdb;
    $t = woodcrest_v25_tables();
    if (!woodcrest_fe_table_exists($t['audit'])) return false;
    return $wpdb->insert($t['audit'], [
        'target_kind' => $kind, 'target_id' => $id, 'supplier_invoice_id' => $invoice_id,
        'actor_id' => get_current_user_id(), 'action_name' => $action,
        'before_json' => $before === null ? null : wp_json_encode($before),
        'after_json' => wp_json_encode($after), 'created_at' => current_time('mysql'),
    ]) !== false;
}

function woodcrest_v25_utility_type($type) {
    $type = strtoupper((string)$type);
    if (in_array($type, ['WA', 'WATER', 'IR', 'IRRIGATION'], true)) return 'WATER';
    if (in_array($type, ['EL', 'ELECTRIC', 'ELECTRICITY'], true)) return 'ELECTRIC';
    return '';
}

function woodcrest_v25_candidates($property_id, $fields) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $mapping = woodcrest_v25_tables()['mapping'];
    $accounts = $wpdb->get_results($wpdb->prepare(
        "SELECT utility_account_id, utility_type, account_number, utility_company, status FROM {$t['utility_account']} WHERE property_id=%d ORDER BY utility_type,utility_company,account_number",
        $property_id), ARRAY_A);
    $meters = $wpdb->get_results($wpdb->prepare(
        "SELECT m.meter_id,m.meter_number,m.meter_type,m.utility_type,m.utility_account_id,m.status FROM {$t['meter']} m WHERE m.property_id=%d ORDER BY m.utility_type,m.meter_type,m.meter_number",
        $property_id), ARRAY_A);
    $provider_account = trim((string)($fields['account_number'] ?? ''));
    $known = woodcrest_fe_table_exists($mapping) ? $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$mapping} WHERE property_id=%d AND provider_account=%s", $property_id, $provider_account), ARRAY_A) : [];
    $account_map = [];
    $meter_map = [];
    foreach ($known as $entry) {
        if ($entry['provider_meter'] === '') $account_map[$entry['utility_type']] = (int)$entry['utility_account_id'];
        else $meter_map[$entry['utility_type'] . '|' . $entry['provider_meter']] = (int)$entry['meter_id'];
    }
    $resolved = [];
    $warnings = [];
    foreach ((array)($fields['meter_rows'] ?? []) as $i => $meter) {
        $type = woodcrest_v25_utility_type($meter['type'] ?? '');
        $matched = 0;
        if ($type) {
            $key = $type . '|' . ($meter['number'] ?? '');
            foreach ($meters as $candidate) {
                if ((int)$candidate['meter_id'] === (int)($meter_map[$key] ?? 0) && $candidate['utility_type'] === $type && $candidate['status'] === 'ACTIVE') $matched = (int)$candidate['meter_id'];
            }
            if (!$matched) {
                $matches = array_values(array_filter($meters, static function($candidate) use($meter,$type,$accounts,$provider_account) {
                    if ($candidate['utility_type'] !== $type || $candidate['status'] !== 'ACTIVE' || strcasecmp($candidate['meter_number'], (string)($meter['number'] ?? '')) !== 0) return false;
                    foreach ($accounts as $account) if ((int)$account['utility_account_id'] === (int)$candidate['utility_account_id'] && strcasecmp($account['account_number'], $provider_account) === 0) return true;
                    return false;
                }));
                if (count($matches) === 1) $matched = (int)$matches[0]['meter_id'];
            }
        }
        if ($matched && isset($meter['previous'],$meter['current']) && is_numeric($meter['previous']) && is_numeric($meter['current'])) {
            $usage = (float)$meter['current'] - (float)$meter['previous'];
            $past_usage = $wpdb->get_var($wpdb->prepare(
                "SELECT usage_amount FROM {$t['utility_reading']} WHERE meter_id=%d AND reading_date < %s ORDER BY reading_date DESC LIMIT 1",
                $matched, (string)($fields['period_end'] ?? '9999-12-31')));
            if ($past_usage !== null && (float)$past_usage > 0 && $usage > 3 * (float)$past_usage) {
                $warnings[] = 'Unusually high usage for meter ' . $meter['number'] . '. Verify the original PDF.';
            }
        }
        $resolved[] = ['row_index'=>$i, 'number'=>$meter['number'] ?? '', 'type'=>$type, 'meter_id'=>$matched];
    }
    $costs = [];
    foreach (['WATER','ELECTRIC'] as $type) {
        $category = $type === 'ELECTRIC' ? 'ELECTRICITY' : 'WATER';
        $amount = 0.0;
        foreach ((array)($fields['charge_rows'] ?? []) as $charge) {
            if (($charge['category'] ?? '') === $category && ($charge['allocation'] ?? '') === 'TENANT_REFERENCE') $amount += (float)$charge['amount'];
        }
        if ($amount <= 0) continue;
        $account_id = (int)($account_map[$type] ?? 0);
        if (!$account_id) {
            $matches = array_values(array_filter($accounts, static fn($a) => $a['utility_type'] === $type && $a['status'] === 'ACTIVE' && strcasecmp($a['account_number'], $provider_account) === 0));
            if (count($matches) === 1) $account_id = (int)$matches[0]['utility_account_id'];
        }
        $rate = '';
        $rate_source = '';
        $explicit = trim((string)($fields[$type === 'WATER' ? 'water_rate' : 'electric_rate'] ?? ''));
        if ($account_id && preg_match('/^\d{1,6}(?:\.\d{1,6})?$/', $explicit) && (float)$explicit > 0) {
            $rate = $explicit;
            $rate_source = 'Unit rate printed on invoice — verify';
        }
        if ($account_id) {
            $matching_usage = [];
            $master_usage = [];
            foreach ($resolved as $match) {
                if (!$match['meter_id'] || $match['type'] !== $type) continue;
                foreach ($meters as $meter) {
                    if ((int)$meter['meter_id'] !== (int)$match['meter_id'] || (int)$meter['utility_account_id'] !== $account_id) continue;
                    $source = $fields['meter_rows'][$match['row_index']] ?? [];
                    if ($type === 'WATER' && in_array(strtoupper((string)($source['type'] ?? '')), ['IR','IRRIGATION'], true)) continue;
                    if (!is_numeric($source['previous'] ?? null) || !is_numeric($source['current'] ?? null)) continue;
                    $usage = (float)$source['current'] - (float)$source['previous'];
                    if ($usage <= 0) continue;
                    $matching_usage[] = $usage;
                    if ($meter['meter_type'] === 'MASTER') $master_usage[] = $usage;
                }
            }
            $provider_usage = count($master_usage) === 1 ? $master_usage[0] : (count($matching_usage) === 1 ? $matching_usage[0] : 0);
            if ($provider_usage > 0 && $rate === '') {
                $calculated = $amount / $provider_usage;
                if ($calculated > 0 && $calculated < 1000000) {
                    $rate = number_format($calculated, 6, '.', '');
                    $rate_source = 'Calculated from invoice charge / matching usage — verify';
                }
            }
            if ($rate === '') {
                $old = $wpdb->get_var($wpdb->prepare(
                    "SELECT unit_rate FROM {$t['utility_monthly_cost']} WHERE utility_account_id=%d AND period_end < %s ORDER BY period_end DESC LIMIT 1",
                    $account_id, (string)($fields['period_start'] ?? '9999-12-31')));
                if ($old !== null) { $rate = (string)$old; $rate_source = 'Previous recorded rate — verify'; }
            }
        }
        $costs[] = ['type'=>$type,'category'=>$category,'amount'=>number_format($amount,2,'.',''),'account_id'=>$account_id,'unit_rate'=>$rate,'rate_source'=>$rate_source];
    }
    return ['accounts'=>$accounts,'meters'=>$meters,'readings'=>$resolved,'costs'=>$costs,'warnings'=>$warnings];
}

function woodcrest_v25_validate_posting($property_id, $fields, $choices) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $errors = [];
    $proposals = ['readings'=>[], 'costs'=>[], 'mappings'=>[], 'warnings'=>[]];
    if (!is_array($choices)) return [null, ['Invalid utility posting selections.']];
    $start = $fields['period_start'] ?? '';
    $end = $fields['period_end'] ?? '';
    if (!woodcrest_fe_date_is_valid($start) || !woodcrest_fe_date_is_valid($end) || $start > $end) return [null, ['Valid service period required.']];
    $account_number = trim((string)($fields['account_number'] ?? ''));
    if ($account_number !== '') {
        $other_property = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT property_id FROM {$t['utility_account']} WHERE account_number=%s AND property_id<>%d LIMIT 1",
            $account_number, $property_id));
        if ($other_property) {
            return [null, ['This provider account belongs to another Woodcrest property. Select that property instead of posting these records.']];
        }
    }
    $used_meters = [];
    $readings = (array)($choices['readings'] ?? []);
    if (count($readings) > 15 || count((array)($choices['costs'] ?? [])) > 2) return [null, ['Too many posting selections.']];
    foreach ($readings as $choice) {
        $index = isset($choice['row_index']) ? (int)$choice['row_index'] : -1;
        $id = absint($choice['meter_id'] ?? 0);
        if (!$id) continue;
        $row = $fields['meter_rows'][$index] ?? null;
        if (!is_array($row) || isset($used_meters[$id])) { $errors[] = 'Duplicate or invalid meter selection.'; continue; }
        $used_meters[$id] = true;
        $meter = woodcrest_utility_meter_record($id);
        $type = woodcrest_v25_utility_type($row['type'] ?? '');
        if (!$meter || (int)$meter->property_id !== $property_id || $type !== $meter->utility_type || $meter->status !== 'ACTIVE') { $errors[] = 'Selected meter is not an active meter of the correct utility type for this property.'; continue; }
        $other_meter_property = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT property_id FROM {$t['meter']} WHERE meter_number=%s AND utility_type=%s AND property_id<>%d LIMIT 1",
            $row['number'], $type, $property_id));
        if ($other_meter_property && strcasecmp((string)$row['number'], (string)$meter->meter_number) !== 0) {
            $errors[] = 'Invoice meter ' . $row['number'] . ' is registered at another property. Review the selected property.';
            continue;
        }
        if ($row['previous'] === '' || $row['current'] === '') { $errors[] = 'Meter ' . $row['number'] . ' requires previous and current readings to post.'; continue; }
        $existing = woodcrest_utility_existing_month_reading($id, $end);
        if ($existing) { $errors[] = 'Meter ' . $meter->meter_number . ' already has a reading for ' . $end . '. Review or correct it in Manage Utilities.'; continue; }
        $prior = woodcrest_utility_previous_reading($id, $end);
        $effective_start = ($prior && (string)$prior->reading_date === (string)$start)
            ? date('Y-m-d', strtotime($start . ' +1 day')) : $start;
        if ($effective_start > $end) { $errors[] = 'Invalid reading period for meter ' . $meter->meter_number . '.'; continue; }
        $overlap = $wpdb->get_var($wpdb->prepare("SELECT reading_id FROM {$t['utility_reading']} WHERE meter_id=%d AND period_start <= %s AND period_end >= %s LIMIT 1", $id, $end, $effective_start));
        if ($overlap) { $errors[] = 'Meter ' . $meter->meter_number . ' already has a reading overlapping this period.'; continue; }
        $prior_read = (float)$row['previous'];
        if ($prior) {
            $prior_next_day = date('Y-m-d', strtotime($prior->reading_date . ' +1 day'));
            if ($prior_next_day === $effective_start) {
                if (abs((float)$prior->current_read - $prior_read) > 0.01) {
                    $errors[] = 'Previous reading conflicts with the immediately preceding period for ' . $meter->meter_number . '.'; continue;
                }
            } elseif ($prior_next_day < $effective_start) {
                if ($prior_read < (float)$prior->current_read) { $errors[] = 'Meter ' . $meter->meter_number . ' historical readings decrease across a gap.'; continue; }
                $proposals['warnings'][] = 'Historical gap before ' . $meter->meter_number . ' is preserved; no missing usage is invented.';
            } else {
                $errors[] = 'Meter ' . $meter->meter_number . ' conflicts with an earlier period.'; continue;
            }
        }
        if ((float)$row['current'] < $prior_read || $prior_read < 0) { $errors[] = 'Meter ' . $meter->meter_number . ' reading is lower than its previous reading.'; continue; }
        $next = $wpdb->get_row($wpdb->prepare("SELECT reading_id, reading_date, period_start, previous_read FROM {$t['utility_reading']} WHERE meter_id=%d AND reading_date > %s ORDER BY reading_date ASC LIMIT 1",$id,$end));
        if ($next) {
            if (empty($next->period_start)) { $errors[] = 'Later meter history has no verified billing period. Review that reading before importing historical data.'; continue; }
            $next_day = date('Y-m-d', strtotime($end . ' +1 day'));
            if ((string)$next->period_start === $next_day && abs((float)$next->previous_read - (float)$row['current']) > 0.01) {
                $errors[] = 'Historical reading conflicts with the following period for meter ' . $meter->meter_number . '.'; continue;
            }
            if ((string)$next->period_start > $next_day) {
                if ((float)$next->previous_read < (float)$row['current']) { $errors[] = 'Historical meter reading exceeds the following reading for ' . $meter->meter_number . '.'; continue; }
                $proposals['warnings'][] = 'Historical gap after meter ' . $meter->meter_number . ' is preserved.';
            }
        }
        $usage = (float)$row['current'] - $prior_read;
        if ($prior) {
            $old_usage = $wpdb->get_var($wpdb->prepare("SELECT usage_amount FROM {$t['utility_reading']} WHERE meter_id=%d AND reading_date < %s ORDER BY reading_date DESC LIMIT 1",$id,$end));
            if ($old_usage !== null && (float)$old_usage > 0 && $usage > (float)$old_usage * 3) $proposals['warnings'][] = 'Unusual usage detected for meter ' . $meter->meter_number . '. Confirm before saving.';
        }
        if ($effective_start !== $start) $proposals['warnings'][] = 'Shared statement boundary normalized for meter ' . $meter->meter_number . ': utility data starts ' . $effective_start . '.';
        if ($next) {
            $existing_invoice = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t['invoice']} WHERE property_id=%d AND period_start<=%s AND period_end>=%s",
                $property_id, $end, $effective_start));
            if ($existing_invoice) $proposals['warnings'][] = 'This historical period has tenant invoices; existing invoices will not be recalculated.';
        }
        $proposals['readings'][] = ['meter'=>$meter,'source_meter'=>$row['number'],'period_start'=>$effective_start,'previous'=>$prior_read,'current'=>(float)$row['current'],'usage'=>$usage];
        $proposals['mappings'][] = ['property_id'=>$property_id,'utility_type'=>$type,'provider_account'=>$account_number,'provider_meter'=>$row['number'],'utility_account_id'=>(int)$meter->utility_account_id,'meter_id'=>$id];
    }
    $used_types = [];
    foreach ((array)($choices['costs'] ?? []) as $choice) {
        $type = strtoupper((string)($choice['type'] ?? ''));
        $id = absint($choice['account_id'] ?? 0);
        if (!$id) continue;
        if (!in_array($type,['WATER','ELECTRIC'],true) || isset($used_types[$type])) { $errors[] = 'Invalid utility cost selection.'; continue; }
        $used_types[$type] = true;
        $account = woodcrest_utility_account_record($id);
        if (!$account || (int)$account->property_id !== $property_id || $account->utility_type !== $type || $account->status !== 'ACTIVE') { $errors[] = 'Utility cost account is not active for this property.'; continue; }
        $rx = trim((string)($choice['unit_rate'] ?? ''));
        if (!preg_match('/^\d{1,6}(?:\.\d{1,6})?$/', $rx)) { $errors[] = 'Enter and confirm a valid unit rate for ' . $type . '.'; continue; }
        $amount = 0;
        foreach ($fields['charge_rows'] as $charge) {
            if ($charge['category'] === ($type === 'ELECTRIC' ? 'ELECTRICITY' : 'WATER') && $charge['allocation'] === 'TENANT_REFERENCE') $amount += (float)$charge['amount'];
        }
        if ($amount <= 0) { $errors[] = 'No tenant-allocated ' . $type . ' charge exists on this invoice.'; continue; }
        $last_end = $wpdb->get_var($wpdb->prepare("SELECT period_end FROM {$t['utility_monthly_cost']} WHERE utility_account_id=%d AND period_end <= %s ORDER BY period_end DESC LIMIT 1",$id,$end));
        $effective_cost_start = $last_end === $start ? date('Y-m-d',strtotime($start.' +1 day')) : $start;
        if ($effective_cost_start > $end) { $errors[] = 'Invalid utility cost period for account ' . $account->account_number . '.'; continue; }
        $existing = $wpdb->get_var($wpdb->prepare("SELECT monthly_cost_id FROM {$t['utility_monthly_cost']} WHERE utility_account_id=%d AND period_start<=%s AND period_end>=%s LIMIT 1",$id,$end,$effective_cost_start));
        if ($existing) { $errors[] = 'An overlapping period cost already exists for account ' . $account->account_number . '. Review it in Manage Utilities.'; continue; }
        if ($effective_cost_start !== $start) $proposals['warnings'][] = 'Shared statement boundary normalized for account ' . $account->account_number . ': cost period starts ' . $effective_cost_start . '.';
        $proposals['costs'][] = ['account'=>$account,'type'=>$type,'period_start'=>$effective_cost_start,'rate'=>(float)$rx,'amount'=>round($amount,2)];
        $proposals['mappings'][] = ['property_id'=>$property_id,'utility_type'=>$type,'provider_account'=>$account_number,'provider_meter'=>'','utility_account_id'=>$id,'meter_id'=>0];
    }
    return [$proposals, array_values(array_unique($errors))];
}

function woodcrest_v25_post_invoice($invoice_id, $property_id, $fields, $choices) {
    global $wpdb;
    $tables = woodcrest_v25_tables();
    $t = woodcrest_fe_tables();
    [$plan, $errors] = woodcrest_v25_validate_posting($property_id, $fields, $choices);
    if ($errors) return new WP_Error('posting_conflict', implode(' ', $errors));
    $counts = ['readings'=>0,'costs'=>0];
    foreach ($plan['readings'] as $item) {
        $meter = $item['meter'];
        $data = [
            'meter_id'=>(int)$meter->meter_id,'reading_date'=>$fields['period_end'],
            'period_start'=>$item['period_start'],'period_end'=>$fields['period_end'],'period_source'=>'VERIFIED',
            'current_read'=>$item['current'],'previous_read'=>$item['previous'],'usage_amount'=>$item['usage'],
            'usage_unit'=>woodcrest_utility_unit_for_type($meter->utility_type),'locked'=>0,
            'entered_by_user_id'=>get_current_user_id(),'created_at'=>current_time('mysql'),
        ];
        if (!$wpdb->insert($t['utility_reading'],$data)) return new WP_Error('posting_failed','Could not post reading for ' . $meter->meter_number . '.');
        $id = (int)$wpdb->insert_id;
        if (!$wpdb->insert($tables['posting'], ['supplier_invoice_id'=>$invoice_id,'target_kind'=>'reading','target_id'=>$id,'original_values'=>wp_json_encode($data),'created_at'=>current_time('mysql')])) return new WP_Error('posting_failed','Could not link the imported meter reading.');
        if (!woodcrest_v25_audit('reading',$id,'PDF_IMPORT',null,$data,$invoice_id)) return new WP_Error('posting_failed','Could not record reading audit history.');
        $counts['readings']++;
    }
    foreach ($plan['costs'] as $item) {
        $account = $item['account'];
        $data = ['utility_account_id'=>(int)$account->utility_account_id,'period_start'=>$item['period_start'],
            'period_end'=>$fields['period_end'],'period_source'=>'VERIFIED','unit_rate'=>$item['rate'],
            'fixed_charge'=>0,'other_charge'=>0,'provider_bill_total'=>$item['amount'],
            'notes'=>'Confirmed utility charge from imported invoice #' . $invoice_id,
            'created_by_user_id'=>get_current_user_id(),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')];
        if (!$wpdb->insert($t['utility_monthly_cost'],$data)) return new WP_Error('posting_failed','Could not post utility period cost for ' . $account->account_number . '.');
        $id = (int)$wpdb->insert_id;
        if (!$wpdb->insert($tables['posting'], ['supplier_invoice_id'=>$invoice_id,'target_kind'=>'cost','target_id'=>$id,'original_values'=>wp_json_encode($data),'created_at'=>current_time('mysql')])) return new WP_Error('posting_failed','Could not link the imported utility cost.');
        if (!woodcrest_v25_audit('cost',$id,'PDF_IMPORT',null,$data,$invoice_id)) return new WP_Error('posting_failed','Could not record cost audit history.');
        $counts['costs']++;
    }
    foreach ($plan['mappings'] as $map) {
        if (!$map['provider_account']) continue;
        $map['verified_by'] = get_current_user_id();
        $map['updated_at'] = current_time('mysql');
        if ($wpdb->replace($tables['mapping'], $map) === false) return new WP_Error('posting_failed','Could not save verified account/meter mapping.');
    }
    return ['counts'=>$counts,'warnings'=>$plan['warnings']];
}

function woodcrest_v25_ajax_candidates() {
    $property_id = absint($_POST['property_id'] ?? 0);
    woodcrest_ocr_authorized_ajax($property_id);
    if (!woodcrest_ocr_has_property_access($property_id)) wp_send_json_error(['message'=>'Property access denied.'],403);
    $fields = json_decode(wp_unslash((string)($_POST['fields'] ?? '')),true);
    if (!is_array($fields)) wp_send_json_error(['message'=>'Invalid invoice fields.'],400);
    wp_send_json_success(woodcrest_v25_candidates($property_id,$fields));
}
add_action('wp_ajax_woodcrest_v25_candidates','woodcrest_v25_ajax_candidates');

function woodcrest_v25_monthly_status_shortcode() {
    if (!is_user_logged_in() || (!woodcrest_fe_is_property_manager() && !current_user_can('manage_options'))) return '';
    $properties = woodcrest_ocr_allowed_property_ids();
    if (!$properties) return '';
    global $wpdb;
    $t = woodcrest_fe_tables();
    $selected = absint($_GET['wc_status_property'] ?? 0);
    if (!in_array($selected,$properties,true)) $selected = (int)$properties[0];
    $period = sanitize_text_field((string)($_GET['wc_status_month'] ?? current_time('Y-m')));
    if (!preg_match('/^\d{4}-(0[1-9]|1[012])$/',$period)) $period=current_time('Y-m');
    $from=$period.'-01';$to=date('Y-m-t',strtotime($from));
    $accounts=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['utility_account']} WHERE property_id=%d AND status='ACTIVE'",$selected));
    $meters_expected=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['meter']} WHERE property_id=%d AND status='ACTIVE'",$selected));
    $types=[];
    foreach (['WATER','ELECTRIC'] as $type) {
        $expected=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['utility_account']} WHERE property_id=%d AND utility_type=%s AND status='ACTIVE'",$selected,$type));
        $recorded=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ua.utility_account_id) FROM {$t['utility_monthly_cost']} mc JOIN {$t['utility_account']} ua ON ua.utility_account_id=mc.utility_account_id WHERE ua.property_id=%d AND ua.utility_type=%s AND mc.period_end BETWEEN %s AND %s",$selected,$type,$from,$to));
        $types[$type]=['expected'=>$expected,'recorded'=>$recorded];
    }
    $readings=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT m.meter_id) FROM {$t['utility_reading']} ur JOIN {$t['meter']} m ON m.meter_id=ur.meter_id WHERE m.property_id=%d AND ur.period_end BETWEEN %s AND %s",$selected,$from,$to));
    $invoice_table=woodcrest_ocr_table();
    $confirmed=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$invoice_table} WHERE property_id=%d AND status='CONFIRMED' AND JSON_UNQUOTE(JSON_EXTRACT(invoice_json,'$.period_end')) BETWEEN %s AND %s",$selected,$from,$to));
    $invoices=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['invoice']} WHERE property_id=%d AND period_end BETWEEN %s AND %s",$selected,$from,$to));
    $names=$wpdb->get_results('SELECT property_id,property_name FROM '.$t['property'].' WHERE property_id IN ('.implode(',',array_map('intval',$properties)).') ORDER BY property_name');
    $html='<section class="woodcrest-card woodcrest-card-wide wc-v25-status"><div class="woodcrest-card-header"><h2>Monthly Utility Status</h2></div>';
    $html.='<form method="get" class="woodcrest-fe-form wc-v25-status-form"><label>Property<select name="wc_status_property">';
    foreach($names as $p) $html.='<option value="'.(int)$p->property_id.'" '.selected($selected,(int)$p->property_id,false).'>'.esc_html($p->property_name).'</option>';
    $html.='</select></label><label>Month<input type="month" name="wc_status_month" value="'.esc_attr($period).'"></label><button type="submit" class="woodcrest-fe-button">Apply</button></form>';
    $html.='<div class="wc-v25-status-items">';
    foreach (['WATER'=>'Water','ELECTRIC'=>'Electricity'] as $key=>$label) {
        $state=$types[$key]['expected'] === 0 ? 'Not configured' : ($types[$key]['recorded'] >= $types[$key]['expected'] ? 'Recorded' : 'Pending');
        $html.='<div>'.esc_html($label).' costs <strong>'.esc_html($state).'</strong></div>';
    }
    $html.='<div>Meter readings <strong>'.(int)$readings.' / '.(int)$meters_expected.'</strong></div><div>Invoices imported <strong>'.(int)$confirmed.'</strong></div><div>Tenant invoices generated <strong>'.(int)$invoices.'</strong></div></div></section>';
    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('woodcrest_manager_monthly_status','woodcrest_v25_monthly_status_shortcode');

function woodcrest_v25_reading_source($reading_id) {
    global $wpdb;
    $t = woodcrest_v25_tables();
    if (!woodcrest_fe_table_exists($t['posting'])) return 'Manual';
    $invoice_id = (int)$wpdb->get_var($wpdb->prepare("SELECT supplier_invoice_id FROM {$t['posting']} WHERE target_kind='reading' AND target_id=%d",$reading_id));
    if (!$invoice_id) return 'Manual';
    $edited = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['audit']} WHERE target_kind='reading' AND target_id=%d AND action_name='MANUAL_EDIT'",$reading_id));
    return 'PDF #' . $invoice_id . ($edited ? ' (Edited)' : '');
}

function woodcrest_v25_source_labels($kind, $ids) {
    global $wpdb;
    $ids = array_values(array_unique(array_filter(array_map('absint',(array)$ids))));
    if (!$ids) return [];
    $tables=woodcrest_v25_tables();
    if (!woodcrest_fe_table_exists($tables['posting'])) return [];
    $placeholders=implode(',',array_fill(0,count($ids),'%d'));
    $rows=$wpdb->get_results($wpdb->prepare("SELECT target_id,supplier_invoice_id FROM {$tables['posting']} WHERE target_kind=%s AND target_id IN ({$placeholders})", ...array_merge([$kind],$ids)), ARRAY_A);
    $result=[];
    foreach ((array)$rows as $r) $result[(int)$r['target_id']]='PDF #'.(int)$r['supplier_invoice_id'];
    if (!$result) return $result;
    if (woodcrest_fe_table_exists($tables['audit'])) {
        $edited=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT target_id FROM {$tables['audit']} WHERE target_kind=%s AND action_name='MANUAL_EDIT' AND target_id IN ({$placeholders})", ...array_merge([$kind],$ids)));
        foreach ((array)$edited as $id) if (isset($result[(int)$id])) $result[(int)$id].=' (Edited)';
    }
    return $result;
}
