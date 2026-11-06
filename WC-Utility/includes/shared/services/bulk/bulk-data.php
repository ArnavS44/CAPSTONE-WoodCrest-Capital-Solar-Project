<?php
if (!defined('ABSPATH')) exit;

 
function woodcrest_v24_categories() {
    return [
        'properties' => ['table'=>'property', 'pk'=>'property_id', 'headers'=>['external_key','entity_name','property_name','address_line1','city','state','zip','timezone','status'], 'required'=>['entity_name','property_name','address_line1','city','state','zip']],
        'suites' => ['table'=>'suite', 'pk'=>'suite_id', 'headers'=>['external_key','property_key','suite_label','square_feet'], 'required'=>['property_key','suite_label','square_feet']],
        'businesses' => ['table'=>'tenant_business', 'pk'=>'business_id', 'headers'=>['external_key','business_name','primary_user_email'], 'required'=>['business_name','primary_user_email']],
        'utility_accounts' => ['table'=>'utility_account', 'pk'=>'utility_account_id', 'headers'=>['external_key','property_key','utility_type','account_number','utility_company','esi_id','status'], 'required'=>['property_key','utility_type','account_number','utility_company']],
        'meters' => ['table'=>'meter', 'pk'=>'meter_id', 'headers'=>['external_key','account_key','meter_number','meter_type','utility_type','meter_name','serial_number','status'], 'required'=>['account_key','meter_number','meter_type','utility_type']],
        'occupancies' => ['table'=>'suite_occupancy', 'pk'=>'occupancy_id', 'headers'=>['external_key','suite_key','business_key','start_date','end_date'], 'required'=>['suite_key','business_key','start_date']],
        'manager_access' => ['table'=>'user_property_access', 'pk'=>'access_id', 'headers'=>['external_key','property_key','user_email','start_date','end_date'], 'required'=>['property_key','user_email']],
        'meter_responsibilities' => ['table'=>'meter_responsibility','pk'=>'responsibility_id','headers'=>['external_key','meter_key','suite_key','business_key','responsible_party_type','allocation_method','fixed_percent','billing_model','monthly_charge_amount','start_date','end_date'], 'required'=>['meter_key','responsible_party_type','allocation_method','billing_model','start_date']],
        'rates' => ['table'=>'rate_schedule', 'pk'=>'rate_schedule_id', 'headers'=>['external_key','business_key','utility_type','base_rate_per_unit','start_date','end_date','notes'], 'required'=>['business_key','utility_type','base_rate_per_unit','start_date']],
        'readings' => ['table'=>'utility_reading', 'pk'=>'reading_id', 'headers'=>['external_key','meter_key','period_start','period_end','reading_date','previous_read','current_read','usage_unit'], 'required'=>['meter_key','period_start','period_end','reading_date','previous_read','current_read','usage_unit']],
        'utility_costs' => ['table'=>'utility_monthly_cost', 'pk'=>'monthly_cost_id', 'headers'=>['external_key','account_key','period_start','period_end','unit_rate','fixed_charge','other_charge','provider_bill_total','notes'], 'required'=>['account_key','period_start','period_end','unit_rate']],
        'nnn_reconciliations' => ['table'=>'nnn_reconciliation', 'pk'=>'nnn_reconciliation_id', 'headers'=>['external_key','property_key','expense_year','billing_year','total_allocable_sqft','woodcrest_responsibility_percent'], 'required'=>['property_key','expense_year','billing_year','total_allocable_sqft']],
        'nnn_expenses' => ['table'=>'nnn_expense', 'pk'=>'nnn_expense_id', 'headers'=>['external_key','reconciliation_key','expense_type','total_cost','rate_per_sqft','notes'], 'required'=>['reconciliation_key','expense_type','total_cost']],
        'representative_access' => ['table'=>'tenant_suite_access','pk'=>'access_id','headers'=>['external_key','business_key','suite_key','user_email','status'], 'required'=>['business_key','suite_key','user_email']],
    ];
}
function woodcrest_v24_refs_table() { global $wpdb; return $wpdb->prefix . 'wc_import_external_ref'; }
function woodcrest_v24_batch_table() { global $wpdb; return $wpdb->prefix . 'wc_import_batch'; }
function woodcrest_v24_install_schema() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = woodcrest_v24_refs_table();
    dbDelta("CREATE TABLE {$table} (
      ref_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      entity_type varchar(48) NOT NULL,
      external_key varchar(128) NOT NULL,
      record_id bigint(20) unsigned NOT NULL,
      created_at datetime NOT NULL,
      PRIMARY KEY  (ref_id),
      UNIQUE KEY entity_external (entity_type,external_key),
      KEY entity_record (entity_type,record_id)
    ) {$wpdb->get_charset_collate()};");
    $batch=woodcrest_v24_batch_table();
    dbDelta("CREATE TABLE {$batch} (
      batch_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      user_id bigint(20) unsigned NOT NULL,
      source_sha256 char(64) NOT NULL,
      categories varchar(255) NOT NULL,
      created_count int(10) unsigned NOT NULL DEFAULT 0,
      updated_count int(10) unsigned NOT NULL DEFAULT 0,
      created_at datetime NOT NULL,
      PRIMARY KEY  (batch_id),
      KEY created_at (created_at)
    ) {$wpdb->get_charset_collate()};");
    return woodcrest_pm_table_exists($table) && woodcrest_pm_table_exists($batch);
}
function woodcrest_v24_data_table($category) {
    global $wpdb;
    $categories = woodcrest_v24_categories();
    if (empty($categories[$category])) return '';
    return $wpdb->prefix . 'wc_' . $categories[$category]['table'];
}
function woodcrest_v24_lookup($category, $key) {
    global $wpdb;
    $key = trim((string)$key);
    if ($key === '') return 0;
    $defs = woodcrest_v24_categories();
    if (!isset($defs[$category])) return 0;
    $table = woodcrest_v24_data_table($category);
    $pk = $defs[$category]['pk'];
    if (preg_match('/^id:([1-9][0-9]*)$/', $key, $match)) {
        return (int)$wpdb->get_var($wpdb->prepare("SELECT `{$pk}` FROM `{$table}` WHERE `{$pk}`=%d", (int)$match[1]));
    }
    return (int)$wpdb->get_var($wpdb->prepare('SELECT record_id FROM `' . woodcrest_v24_refs_table() . '` WHERE entity_type=%s AND external_key=%s', $category, $key));
}
function woodcrest_v24_key_for_id($category, $id) {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare('SELECT external_key FROM `' . woodcrest_v24_refs_table() . '` WHERE entity_type=%s AND record_id=%d ORDER BY ref_id LIMIT 1', $category, $id));
    return $value ?: 'id:' . (int)$id;
}
function woodcrest_v24_ref_register($category,$key,$id) {
    global $wpdb;
    if (strpos($key, 'id:') === 0) return true;
    return $wpdb->insert(woodcrest_v24_refs_table(), ['entity_type'=>$category,'external_key'=>$key,'record_id'=>(int)$id,'created_at'=>current_time('mysql')]) !== false;
}
function woodcrest_v24_date($value, $required = false) {
    $value = trim((string)$value);
    if ($value === '' && !$required) return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) throw new Exception('Dates must use YYYY-MM-DD.');
    $p = explode('-', $value);
    if (!checkdate((int)$p[1],(int)$p[2],(int)$p[0])) throw new Exception('Invalid date: '.$value);
    return $value;
}
function woodcrest_v24_num($value, $allow_blank = false, $min = 0) {
    $value = trim((string)$value);
    if ($value === '' && $allow_blank) return null;
    if ($value === '' || !is_numeric($value) || !is_finite((float)$value) || (float)$value < $min) throw new Exception('Expected a number at least '.$min.'.');
    return $value;
}
function woodcrest_v24_option($val, $options, $fallback = '') {
    $val = strtoupper(trim((string)$val));
    if ($val === '' && $fallback !== '') return $fallback;
    if (!in_array($val,$options,true)) throw new Exception('Invalid value: ' . $val . '. Expected ' . implode('/', $options));
    return $val;
}
function woodcrest_v24_ref($category,$key) {
    $id = woodcrest_v24_lookup($category,$key);
    if (!$id) throw new Exception('Unknown '. $category . ' reference: ' . $key . '. Import its parent file first.');
    return $id;
}
function woodcrest_v24_email_user($email) {
    $user = get_user_by('email', trim($email));
    if (!$user) throw new Exception('WordPress user does not exist for email ' . $email . '. Create the account before importing.');
    return (int)$user->ID;
}
function woodcrest_v24_overlap($table,$pk,$id,$col,$val,$start,$end,$start_col='start_date',$end_col='end_date') {
    global $wpdb;
     
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}`=%d AND `{$pk}`<>%d AND `{$start_col}`<=%s AND (`{$end_col}` IS NULL OR `{$end_col}` >= %s)",
        $val,$id,$end ?: '9999-12-31',$start
    )) > 0;
}
function woodcrest_v24_prepare_record($category,$r,$existing) {
    global $wpdb;
    $now = current_time('mysql');
    $x = static function($key) use ($r) { return isset($r[$key]) ? trim((string)$r[$key]) : ''; };
    $date_range = static function($start,$end) use ($x) {
        $a=woodcrest_v24_date($x($start),true); $b=woodcrest_v24_date($x($end));
        if ($b !== null && $b < $a) throw new Exception('End date cannot be before start date.');
        return [$a,$b];
    };
    switch ($category) {
      case 'properties':
        return ['entity_name'=>$x('entity_name'),'property_name'=>$x('property_name'),'address_line1'=>$x('address_line1'),'city'=>$x('city'),'state'=>$x('state'),'zip'=>$x('zip'),'timezone'=>$x('timezone') ?: 'America/Chicago','status'=>woodcrest_v24_option($x('status'),['ACTIVE','INACTIVE'],'ACTIVE')];
      case 'suites':
        $property=woodcrest_v24_ref('properties',$x('property_key'));
        if ($existing && (int)$existing->property_id !== $property) throw new Exception('Cannot move an existing suite between properties.');
        return ['property_id'=>$property,'suite_label'=>$x('suite_label'),'square_feet'=>woodcrest_v24_num($x('square_feet'))];
      case 'businesses':
        $uid=woodcrest_v24_email_user($x('primary_user_email'));
        if ($existing && (int)$existing->primary_user_id !== $uid) throw new Exception('Cannot change the billing account owner through CSV.');
        $others=(int)$wpdb->get_var($wpdb->prepare('SELECT business_id FROM `'.$wpdb->prefix.'wc_tenant_business` WHERE primary_user_id=%d',$uid));
        if ($others && (!$existing || $others !== (int)$existing->business_id)) throw new Exception('This WordPress user already owns another business. Reference that business instead.');
        return ['business_name'=>$x('business_name'),'primary_user_id'=>$uid];
      case 'utility_accounts':
        $property=woodcrest_v24_ref('properties',$x('property_key'));
        if ($existing && (int)$existing->property_id !== $property) throw new Exception('Cannot move an existing utility account.');
        return ['property_id'=>$property,'utility_type'=>woodcrest_v24_option($x('utility_type'),['WATER','ELECTRIC']),'account_number'=>$x('account_number'),'utility_company'=>$x('utility_company'),'esi_id'=>$x('esi_id') ?: null,'status'=>woodcrest_v24_option($x('status'),['ACTIVE','INACTIVE'],'ACTIVE')];
      case 'meters':
        $account=woodcrest_v24_ref('utility_accounts',$x('account_key'));
        $a=$wpdb->get_row($wpdb->prepare('SELECT property_id,utility_type FROM `'.$wpdb->prefix.'wc_utility_account` WHERE utility_account_id=%d',$account));
        $utility=woodcrest_v24_option($x('utility_type'),['WATER','ELECTRIC']);
        if ($utility !== $a->utility_type) throw new Exception('Meter utility type must match its utility account.');
        if ($existing && (int)$existing->utility_account_id !== $account) throw new Exception('Cannot reassign an existing meter through CSV.');
        return ['utility_account_id'=>$account,'property_id'=>(int)$a->property_id,'meter_number'=>$x('meter_number'),'meter_type'=>woodcrest_v24_option($x('meter_type'),['MASTER','SUBMETER']),'utility_type'=>$utility,'meter_name'=>$x('meter_name') ?: null,'serial_number'=>$x('serial_number') ?: null,'status'=>woodcrest_v24_option($x('status'),['ACTIVE','INACTIVE'],'ACTIVE')];
      case 'occupancies':
        [$start,$end]=$date_range('start_date','end_date');
        $suite=woodcrest_v24_ref('suites',$x('suite_key')); $business=woodcrest_v24_ref('businesses',$x('business_key'));
        $biz=$wpdb->get_row($wpdb->prepare('SELECT primary_user_id FROM `'.$wpdb->prefix.'wc_tenant_business` WHERE business_id=%d',$business));
        if ($existing && ((int)$existing->suite_id !== $suite || (int)$existing->business_id !== $business)) throw new Exception('Cannot change occupancy suite/business through CSV.');
        if (woodcrest_v24_overlap($wpdb->prefix.'wc_suite_occupancy','occupancy_id',$existing->occupancy_id ?? 0,'suite_id',$suite,$start,$end)) throw new Exception('Overlapping occupancy dates for this suite.');
        return ['suite_id'=>$suite,'business_id'=>$business,'tenant_user_id'=>(int)$biz->primary_user_id,'start_date'=>$start,'end_date'=>$end];
      case 'manager_access':
        $property=woodcrest_v24_ref('properties',$x('property_key')); $user=woodcrest_v24_email_user($x('user_email'));
        $start=woodcrest_v24_date($x('start_date')); $end=woodcrest_v24_date($x('end_date'));
        if ($start && $end && $end < $start) throw new Exception('Invalid manager dates.');
        if ($existing && ((int)$existing->property_id!==$property || (int)$existing->user_id!==$user)) throw new Exception('Cannot reassign manager access identity.');
        return ['property_id'=>$property,'user_id'=>$user,'access_scope'=>'PROPERTY_MANAGER','start_date'=>$start,'end_date'=>$end];
      case 'meter_responsibilities':
        [$start,$end]=$date_range('start_date','end_date');
        $meter=woodcrest_v24_ref('meters',$x('meter_key'));
        $m=$wpdb->get_row($wpdb->prepare('SELECT property_id,utility_account_id FROM `'.$wpdb->prefix.'wc_meter` WHERE meter_id=%d',$meter));
        $party=woodcrest_v24_option($x('responsible_party_type'),['TENANT','CAM','VACANT_WOODCREST']);
        $method=woodcrest_v24_option($x('allocation_method'),['ACTUAL','PRO_RATA_SF','FIXED_PERCENT']);
        $model=woodcrest_v24_option($x('billing_model'),['PASS_THROUGH','RESALE']);
        $suite=$x('suite_key')?woodcrest_v24_ref('suites',$x('suite_key')):null;
        $business=$x('business_key')?woodcrest_v24_ref('businesses',$x('business_key')):0;
        $uid=null;
        if ($party==='TENANT') {
            if (!$suite||!$business) throw new Exception('TENANT responsibility needs suite_key and business_key.');
            $associated=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.$wpdb->prefix.'wc_suite_occupancy` WHERE suite_id=%d AND business_id=%d AND start_date<=%s AND (end_date IS NULL OR end_date>=%s)',$suite,$business,$end?:'9999-12-31',$start));
            if (!$associated) throw new Exception('TENANT responsibility must have an overlapping occupancy.');
            $uid=(int)$wpdb->get_var($wpdb->prepare('SELECT primary_user_id FROM `'.$wpdb->prefix.'wc_tenant_business` WHERE business_id=%d',$business));
        } elseif ($business) throw new Exception('Only TENANT responsibility can specify business_key.');
        if ($suite) {
            $sp=(int)$wpdb->get_var($wpdb->prepare('SELECT property_id FROM `'.$wpdb->prefix.'wc_suite` WHERE suite_id=%d',$suite));
            if ($sp!==(int)$m->property_id) throw new Exception('Meter and suite must be at the same property.');
        }
        $percent=woodcrest_v24_num($x('fixed_percent'),true);
        if ($method==='FIXED_PERCENT' && ($percent===null|| (float)$percent>100)) throw new Exception('FIXED_PERCENT requires fixed_percent between 0 and 100.');
        if ($method!=='FIXED_PERCENT') $percent=null;
        if ($existing && ((int)$existing->meter_id!==$meter || (int)$existing->suite_id!==(int)$suite || (int)$existing->tenant_user_id!==(int)$uid)) throw new Exception('Cannot change meter, suite, or billing tenant on an existing responsibility.');
        $table=$wpdb->prefix.'wc_meter_responsibility';
        $conflicts=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE meter_id=%d AND COALESCE(suite_id,0)=%d AND responsible_party_type=%s AND responsibility_id<>%d AND start_date<=%s AND (end_date IS NULL OR end_date >= %s)",$meter,(int)$suite,$party,$existing->responsibility_id??0,$end?:'9999-12-31',$start));
        if ($conflicts) throw new Exception('Overlapping responsibility for this meter, suite, and party.');
        return ['meter_id'=>$meter,'utility_account_id'=>(int)$m->utility_account_id,'suite_id'=>$suite,'tenant_user_id'=>$uid,'responsible_party_type'=>$party,'allocation_method'=>$method,'fixed_percent'=>$percent,'billing_model'=>$model,'monthly_charge_amount'=>woodcrest_v24_num($x('monthly_charge_amount'),true),'start_date'=>$start,'end_date'=>$end];
      case 'rates':
        [$start,$end]=$date_range('start_date','end_date');
        $business=woodcrest_v24_ref('businesses',$x('business_key'));
        $uid=(int)$wpdb->get_var($wpdb->prepare('SELECT primary_user_id FROM `'.$wpdb->prefix.'wc_tenant_business` WHERE business_id=%d',$business));
        $utility=woodcrest_v24_option($x('utility_type'),['WATER','ELECTRIC']);
        if ($existing && ((int)$existing->tenant_user_id!==$uid || $existing->utility_type!==$utility)) throw new Exception('Cannot change a rate owner/type.');
        $table=$wpdb->prefix.'wc_rate_schedule';
        $collision=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE tenant_user_id=%d AND utility_type=%s AND rate_schedule_id<>%d AND start_date<=%s AND (end_date IS NULL OR end_date >= %s)",$uid,$utility,$existing->rate_schedule_id ?? 0,$end?:'9999-12-31',$start));
        if ($collision) throw new Exception('Overlapping tenant rate dates.');
        return ['tenant_user_id'=>$uid,'utility_type'=>$utility,'base_rate_per_unit'=>woodcrest_v24_num($x('base_rate_per_unit')),'start_date'=>$start,'end_date'=>$end,'notes'=>$x('notes') ?: null];
      case 'readings':
        [$start,$end]=$date_range('period_start','period_end');
        $reading=woodcrest_v24_date($x('reading_date'),true);
        if (!$end) throw new Exception('Reading period_end is required.');
        if ($reading < $start || $reading < $end) throw new Exception('Reading date must be on or after period end.');
        $meter=woodcrest_v24_ref('meters',$x('meter_key'));
        if ($existing && (int)$existing->meter_id!==$meter) throw new Exception('Cannot change meter on historical reading.');
        if ($existing && !empty($existing->locked)) throw new Exception('Locked readings cannot be modified.');
        $prev=woodcrest_v24_num($x('previous_read')); $cur=woodcrest_v24_num($x('current_read'));
        if ((float)$cur < (float)$prev) throw new Exception('Current read is less than previous read.');
        $unit=$x('usage_unit'); if (strlen($unit)>20) throw new Exception('Usage unit is too long.');
        $table=$wpdb->prefix.'wc_utility_reading';
        $collision=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE meter_id=%d AND reading_id<>%d AND period_start<=%s AND period_end>=%s",$meter,$existing->reading_id??0,$end,$start));
        if ($collision) throw new Exception('Overlapping meter reading periods.');
        return ['meter_id'=>$meter,'period_start'=>$start,'period_end'=>$end,'period_source'=>'VERIFIED','reading_date'=>$reading,'previous_read'=>$prev,'current_read'=>$cur,'usage_amount'=>(string)round((float)$cur-(float)$prev,4),'usage_unit'=>$unit,'entered_by_user_id'=>get_current_user_id()];
      case 'utility_costs':
        [$start,$end]=$date_range('period_start','period_end'); if (!$end) throw new Exception('Cost period_end is required.');
        $account=woodcrest_v24_ref('utility_accounts',$x('account_key'));
        if ($existing && (int)$existing->utility_account_id !== $account) throw new Exception('Cannot reassign an existing cost record.');
        $table=$wpdb->prefix.'wc_utility_monthly_cost';
        $collision=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE utility_account_id=%d AND monthly_cost_id<>%d AND period_start<=%s AND period_end>=%s",$account,$existing->monthly_cost_id??0,$end,$start));
        if ($collision) throw new Exception('Overlapping provider cost periods.');
        return ['utility_account_id'=>$account,'period_start'=>$start,'period_end'=>$end,'period_source'=>'VERIFIED','unit_rate'=>woodcrest_v24_num($x('unit_rate')),'fixed_charge'=>woodcrest_v24_num($x('fixed_charge') ?: '0'),'other_charge'=>woodcrest_v24_num($x('other_charge') ?: '0'),'provider_bill_total'=>woodcrest_v24_num($x('provider_bill_total'),true),'notes'=>$x('notes') ?: null,'updated_at'=>$now];
      case 'nnn_reconciliations':
        $prop=woodcrest_v24_ref('properties',$x('property_key'));
        $year=(int)woodcrest_v24_num($x('expense_year')); $bill=(int)woodcrest_v24_num($x('billing_year'));
        if ($year<2000 || $year>2100 || $bill<2000 || $bill>2100) throw new Exception('Year must be between 2000 and 2100.');
        if ($existing && (int)$existing->property_id!==$prop) throw new Exception('Cannot reassign reconciliation property.');
        return ['property_id'=>$prop,'expense_year'=>$year,'billing_year'=>$bill,'total_allocable_sqft'=>woodcrest_v24_num($x('total_allocable_sqft')),'woodcrest_responsibility_percent'=>woodcrest_v24_num($x('woodcrest_responsibility_percent') ?: '0'),'status'=>'UNDRAFTED'];
      case 'nnn_expenses':
        $rec=woodcrest_v24_ref('nnn_reconciliations',$x('reconciliation_key'));
        if ($existing && (int)$existing->nnn_reconciliation_id!==$rec) throw new Exception('Cannot reassign NNN expense.');
        if ($existing && (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.$wpdb->prefix.'wc_nnn_allocation` WHERE nnn_expense_id=%d',$existing->nnn_expense_id))) throw new Exception('NNN expense already has tenant allocations; CSV cannot overwrite allocated expenses.');
        return ['nnn_reconciliation_id'=>$rec,'expense_type'=>woodcrest_v24_option($x('expense_type'),['CAM','INSURANCE','PROPERTY_TAX','OTHER']),'total_cost'=>woodcrest_v24_num($x('total_cost')),'rate_per_sqft'=>woodcrest_v24_num($x('rate_per_sqft') ?: '0'),'notes'=>$x('notes') ?: null];
      case 'representative_access':
        $business=woodcrest_v24_ref('businesses',$x('business_key')); $suite=woodcrest_v24_ref('suites',$x('suite_key'));
        $user=woodcrest_v24_email_user($x('user_email'));
        $occupied=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.$wpdb->prefix.'wc_suite_occupancy` WHERE business_id=%d AND suite_id=%d',$business,$suite));
        if (!$occupied) throw new Exception('Business is not associated with this suite. Import occupancy first.');
        if ($existing && ((int)$existing->business_id!==$business || (int)$existing->suite_id!==$suite || (int)$existing->user_id!==$user)) throw new Exception('Cannot transfer representative access identity.');
        return ['business_id'=>$business,'suite_id'=>$suite,'user_id'=>$user,'status'=>woodcrest_v24_option($x('status'),['ACTIVE','REVOKED'],'ACTIVE')];
    }
    throw new Exception('Unsupported import category.');
}
function woodcrest_v24_match_existing($category,$data) {
    global $wpdb;
    $defs=woodcrest_v24_categories(); $table=woodcrest_v24_data_table($category); $pk=$defs[$category]['pk'];
    $keysets=[
      'properties'=>['property_name','address_line1','zip'],
      'suites'=>['property_id','suite_label'],
      'businesses'=>['primary_user_id'],
      'utility_accounts'=>['property_id','account_number','utility_type'],
      'meters'=>['utility_account_id','meter_number'],
      'occupancies'=>['suite_id','business_id','start_date'],
      'manager_access'=>['property_id','user_id','access_scope'],
      'meter_responsibilities'=>['meter_id','suite_id','responsible_party_type','start_date'],
      'rates'=>['tenant_user_id','utility_type','start_date'],
      'readings'=>['meter_id','reading_date'],
      'utility_costs'=>['utility_account_id','period_start','period_end'],
      'nnn_reconciliations'=>['property_id','expense_year','billing_year'],
      'nnn_expenses'=>['nnn_reconciliation_id','expense_type'],
      'representative_access'=>['business_id','suite_id','user_id'],
    ];
    $cols=$keysets[$category] ?? [];
    if (!$cols) return 0;
    $where=[]; $vals=[];
    foreach($cols as $col) { if (!isset($data[$col])) return 0; $where[]='`'.$col.'`=%s'; $vals[]=$data[$col]; }
    $ids=$wpdb->get_col($wpdb->prepare("SELECT `{$pk}` FROM `{$table}` WHERE ".implode(' AND ',$where).' LIMIT 2',$vals));
    if (count($ids)>1) throw new Exception('More than one existing record matches the natural key. Use id:<number> to target one record.');
    return $ids ? (int)$ids[0] : 0;
}
function woodcrest_v24_import_rows($category,$rows,&$stats,&$errors) {
    global $wpdb;
    $defs=woodcrest_v24_categories();
    if (!isset($defs[$category])) throw new Exception('Unknown category: '.$category);
    $table=woodcrest_v24_data_table($category); $pk=$defs[$category]['pk'];
    if (!woodcrest_pm_table_exists($table)) throw new Exception('Missing table for ' . $category . '. Run v2.3 migrations first.');
    foreach($rows as $index=>$r) {
        $line=$index+2;
        try {
            foreach($defs[$category]['required'] as $field) {
                if (trim((string)($r[$field]??''))==='') throw new Exception('Missing required field '.$field.'.');
            }
            $key=trim((string)($r['external_key']??''));
            if (!$key || strlen($key)>128 || !preg_match('/^(?:[a-zA-Z0-9][a-zA-Z0-9_.:@-]*)$/',$key)) throw new Exception('external_key required (letters, numbers, underscores, dots, hyphens).');
            if (preg_match('/^id:[0-9]+$/', $key) && !woodcrest_v24_lookup($category,$key)) throw new Exception('id: references are for existing records only. Use a custom external_key for new records.');
            $id=woodcrest_v24_lookup($category,$key);
            $old=$id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE `{$pk}`=%d",$id)) : null;
            if ($id && !$old) throw new Exception('Reference points to a missing database record.');
            $data=woodcrest_v24_prepare_record($category,$r,$old);
            woodcrest_v24_enforce_row_scope($category,$data,$old);
            if (!$id) {
                $id=woodcrest_v24_match_existing($category,$data);
                if ($id) {
                    $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE `{$pk}`=%d",$id));
                    $data=woodcrest_v24_prepare_record($category,$r,$old);
                    woodcrest_v24_enforce_row_scope($category,$data,$old);
                }
            }
            if ($old && $category==='nnn_reconciliations' && $old->status==='DRAFTED') throw new Exception('Drafted/finalized NNN reconciliation cannot be overwritten through CSV.');
            if (!$id) {
                if (in_array($category,['properties','suites','businesses','utility_accounts','meters','occupancies','manager_access','meter_responsibilities','readings','utility_costs','nnn_expenses','representative_access'],true)) $data['created_at']=current_time('mysql');
                if ($category==='utility_costs') $data['created_by_user_id']=get_current_user_id();
                if ($category==='readings') $data['locked']=0;
                if ($category==='representative_access' && $data['status']!=='ACTIVE') throw new Exception('Only ACTIVE access can be created; revoke an existing record instead.');
                if ($wpdb->insert($table,$data)===false) throw new Exception('Database insert failed: '.$wpdb->last_error);
                $id=(int)$wpdb->insert_id;
                $stats['created']++;
            } else {
                if ($category==='readings') unset($data['entered_by_user_id']);
                if ($category==='representative_access') {
                    $biztable=$wpdb->prefix.'wc_tenant_business';
                    $owner=(int)$wpdb->get_var($wpdb->prepare("SELECT primary_user_id FROM `{$biztable}` WHERE business_id=%d",$data['business_id']));
                    if ($owner===(int)$data['user_id'] && $data['status']!=='ACTIVE') throw new Exception('Cannot revoke primary billing contact via CSV.');
                }
                if ($wpdb->update($table,$data,[$pk=>$id])===false) throw new Exception('Database update failed: '.$wpdb->last_error);
                $stats['updated']++;
            }
            if ($category==='businesses') {
                $members=$wpdb->prefix.'wc_tenant_business_member';
                $member=$wpdb->get_row($wpdb->prepare("SELECT membership_id,business_id FROM `{$members}` WHERE user_id=%d",$data['primary_user_id']));
                if ($member && (int)$member->business_id !== $id) throw new Exception('Business owner already belongs to another tenant business.');
                if (!$member && $wpdb->insert($members,['business_id'=>$id,'user_id'=>$data['primary_user_id'],'status'=>'ACTIVE','created_at'=>current_time('mysql')])===false) throw new Exception($wpdb->last_error);
            }
            if ($category==='representative_access') {
                $members=$wpdb->prefix.'wc_tenant_business_member';
                $member=$wpdb->get_row($wpdb->prepare("SELECT membership_id,business_id FROM `{$members}` WHERE user_id=%d",$data['user_id']));
                if ($member && (int)$member->business_id !== (int)$data['business_id']) throw new Exception('Representative already belongs to another tenant business.');
                if (!$member && $wpdb->insert($members,['business_id'=>$data['business_id'],'user_id'=>$data['user_id'],'status'=>'ACTIVE','created_at'=>current_time('mysql')])===false) throw new Exception($wpdb->last_error);
                if ($member && $wpdb->update($members,['status'=>'ACTIVE'],['membership_id'=>$member->membership_id])===false) throw new Exception($wpdb->last_error);
            }
            if ($category==='occupancies') {
                $today=current_time('Y-m-d');
                $occupied=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.$wpdb->prefix.'wc_suite_occupancy` WHERE suite_id=%d AND start_date<=%s AND (end_date IS NULL OR end_date>=%s)',$data['suite_id'],$today,$today));
                if ($wpdb->update($wpdb->prefix.'wc_suite',['status'=>$occupied?'OCCUPIED':'VACANT'],['suite_id'=>$data['suite_id']])===false) throw new Exception('Failed updating suite status.');
                if (function_exists('woodcrest_v23_grant_primary_suite') && !woodcrest_v23_grant_primary_suite($data['business_id'],$data['suite_id'])) throw new Exception('Failed to assign suite access to billing contact.');
            }
            if ($category==='properties' && !$old && function_exists('woodcrest_auto_assign_new_property')) {
                woodcrest_auto_assign_new_property($id);
            }
            if (!woodcrest_v24_lookup($category,$key) && !woodcrest_v24_ref_register($category,$key,$id)) throw new Exception('Could not save external reference mapping.');
        } catch (Throwable $e) {
            $errors[]=$category.' row '.$line.': '.$e->getMessage();
            if (count($errors)>=40) throw new Exception('40 errors reached; stopping this import.');
        }
    }
}
function woodcrest_v24_csv_parse($text,$category) {
    $defs=woodcrest_v24_categories();
    if (!isset($defs[$category])) throw new Exception('Unsupported CSV category.');
    $text=preg_replace('/^\xEF\xBB\xBF/','',$text);
    if (strlen($text)>5*1024*1024) throw new Exception('CSV must be 5MB or smaller.');
    $f=fopen('php://temp','w+'); fwrite($f,$text); rewind($f);
    $head=fgetcsv($f,0,',','"','\\');
    if (!$head) throw new Exception('CSV is empty.');
    $head=array_map('trim',$head);
    if (count(array_unique($head))!==count($head)) throw new Exception('Duplicate CSV column headers.');
    foreach($defs[$category]['headers'] as $required) {
        if (!in_array($required,$head,true)) throw new Exception('CSV missing column: '.$required.'. Download the v2.4 template.');
    }
    $rows=[];
    while(($cells=fgetcsv($f,0,',','"','\\'))!==false) {
        if ($cells===[null] || count(array_filter($cells,static function($v){ return trim((string)$v)!==''; }))===0) continue;
        if (count($cells)!==count($head)) throw new Exception('CSV column count mismatch at data row '.(count($rows)+2).'.');
        $rows[]=array_combine($head,$cells);
        if (count($rows)>4000) throw new Exception('CSV row limit is 4000 per file.');
    }
    fclose($f);
    if (!$rows) throw new Exception('CSV has no data rows.');
    return $rows;
}
function woodcrest_v24_files_from_upload($upload,$category) {
    $defs=woodcrest_v24_categories();
    if (!isset($defs[$category])) throw new Exception('Invalid CSV form.');
    if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name']) || (int)($upload['error']??1)!==UPLOAD_ERR_OK) throw new Exception('CSV upload failed.');
    if ((int)($upload['size']??0)>5*1024*1024) throw new Exception('CSV must be under 5MB.');
    if (strtolower(pathinfo((string)($upload['name']??''),PATHINFO_EXTENSION))!=='csv') throw new Exception('Choose a .csv file.');
    return [$category=>woodcrest_v24_csv_parse(file_get_contents($upload['tmp_name']),$category)];
}
function woodcrest_v24_process($file_data,$commit=false,$source_hash='') {
    global $wpdb;
    $stats=['created'=>0,'updated'=>0,'per_file'=>[]]; $errors=[];
    if (!woodcrest_pm_table_exists(woodcrest_v24_refs_table()) || !woodcrest_pm_table_exists(woodcrest_v24_batch_table())) return ['stats'=>$stats,'errors'=>['Import tables are missing. Initialize them first.'],'committed'=>false];
    if (!woodcrest_v23_suite_access_ready()) return ['stats'=>$stats,'errors'=>['Run both v2.3 migrations before using bulk import.'],'committed'=>false];
    $wpdb->query('START TRANSACTION');
    try {
        foreach(woodcrest_v24_categories() as $cat=>$def) {
            if (!isset($file_data[$cat])) continue;
            $before=[$stats['created'],$stats['updated']];
            woodcrest_v24_import_rows($cat,$file_data[$cat],$stats,$errors);
            $stats['per_file'][$cat]=['rows'=>count($file_data[$cat]),'created'=>$stats['created']-$before[0],'updated'=>$stats['updated']-$before[1]];
        }
        if ($errors || !$commit) $wpdb->query('ROLLBACK');
        else {
            $ok=$wpdb->insert(woodcrest_v24_batch_table(),[
                'user_id'=>get_current_user_id(),
                'source_sha256'=>preg_match('/^[a-f0-9]{64}$/',$source_hash)?$source_hash:str_repeat('0',64),
                'categories'=>implode(',',array_keys($file_data)),
                'created_count'=>$stats['created'],'updated_count'=>$stats['updated'],
                'created_at'=>current_time('mysql')
            ]);
            if ($ok===false) throw new Exception('Could not save import audit record: '.$wpdb->last_error);
            if ($wpdb->query('COMMIT')===false) throw new Exception('Commit failed: '.$wpdb->last_error);
        }
    } catch (Throwable $e) {
        $errors[]=$e->getMessage(); $wpdb->query('ROLLBACK');
    }
    return ['stats'=>$stats,'errors'=>$errors,'committed'=>$commit && !$errors];
}
function woodcrest_v24_template_row($cat) {
    $examples=[
      'properties'=>['PROP001','Woodcrest Holdings','Woodcrest Plaza','100 Main St','Fort Worth','TX','76102','America/Chicago','ACTIVE'],
      'suites'=>['SUITE001','PROP001','101','1500'],
      'businesses'=>['BIZ001','Joe Pizza LLC','existing-owner@example.com'],
      'utility_accounts'=>['ACCOUNT001','PROP001','WATER','W-001','City Water','','ACTIVE'],
      'meters'=>['METER001','ACCOUNT001','WM-001','SUBMETER','WATER','Suite 101 Water','','ACTIVE'],
      'occupancies'=>['OCC001','SUITE001','BIZ001','2026-01-01',''],
      'manager_access'=>['ACCESS001','PROP001','existing-manager@example.com','',''],
      'meter_responsibilities'=>['RESP001','METER001','SUITE001','BIZ001','TENANT','ACTUAL','','PASS_THROUGH','','2026-01-01',''],
      'rates'=>['RATE001','BIZ001','WATER','1.250000','2026-01-01','','Example rate'],
      'readings'=>['READ001','METER001','2026-01-01','2026-01-31','2026-01-31','100','130','GAL'],
      'utility_costs'=>['COST001','ACCOUNT001','2026-01-01','2026-01-31','1.250000','10.00','0.00','47.50','Example bill'],
      'nnn_reconciliations'=>['NNN001','PROP001','2026','2027','12000','0'],
      'nnn_expenses'=>['EXP001','NNN001','CAM','5000','0.416667','Annual CAM'],
      'representative_access'=>['REP001','BIZ001','SUITE001','existing-son@example.com','ACTIVE'],
    ];
    return $examples[$cat]??[];
}
function woodcrest_v24_export_csv($category, $manager_scope = null) {
    global $wpdb;
    $defs=woodcrest_v24_categories();
    if (!isset($defs[$category])) throw new Exception('Invalid export category.');
    $table=woodcrest_v24_data_table($category); $def=$defs[$category]; $pk=$def['pk'];
    $rows=$wpdb->get_results("SELECT * FROM `{$table}` ORDER BY `{$pk}` LIMIT 25001",ARRAY_A);
    if (count($rows)>25000) throw new Exception('This dataset exceeds the 25,000-row export limit.');
    $f=fopen('php://temp','w+'); fputcsv($f,$def['headers']);
    foreach($rows as $r) {
        if (is_array($manager_scope) && !in_array(woodcrest_v24_row_property($category,$r),$manager_scope,true)) continue;
        if ($category==='manager_access' && $r['access_scope']!=='PROPERTY_MANAGER') continue;
        $v=['external_key'=>woodcrest_v24_key_for_id($category,$r[$pk])];
        switch($category) {
          case 'suites': case 'utility_accounts': case 'nnn_reconciliations': case 'manager_access':
            $v['property_key']=woodcrest_v24_key_for_id('properties',$r['property_id']); break;
          case 'meters': case 'utility_costs': $v['account_key']=woodcrest_v24_key_for_id('utility_accounts',$r['utility_account_id']); break;
          case 'occupancies': case 'representative_access':
            $v['suite_key']=woodcrest_v24_key_for_id('suites',$r['suite_id']);
            $v['business_key']=woodcrest_v24_key_for_id('businesses',$r['business_id']); break;
          case 'meter_responsibilities':
            $v['meter_key']=woodcrest_v24_key_for_id('meters',$r['meter_id']);
            $v['suite_key']=$r['suite_id']?woodcrest_v24_key_for_id('suites',$r['suite_id']):'';
            $business=$r['tenant_user_id']?(int)$wpdb->get_var($wpdb->prepare('SELECT business_id FROM `'.$wpdb->prefix.'wc_tenant_business` WHERE primary_user_id=%d',$r['tenant_user_id'])):0;
            $v['business_key']=$business?woodcrest_v24_key_for_id('businesses',$business):''; break;
          case 'rates':
            $business=(int)$wpdb->get_var($wpdb->prepare('SELECT business_id FROM `'.$wpdb->prefix.'wc_tenant_business` WHERE primary_user_id=%d',$r['tenant_user_id']));
            if (!$business) continue 2;
            $v['business_key']=woodcrest_v24_key_for_id('businesses',$business); break;
          case 'readings': $v['meter_key']=woodcrest_v24_key_for_id('meters',$r['meter_id']); break;
          case 'nnn_expenses': $v['reconciliation_key']=woodcrest_v24_key_for_id('nnn_reconciliations',$r['nnn_reconciliation_id']); break;
        }
        if ($category==='businesses') { $u=get_userdata((int)$r['primary_user_id']); $v['primary_user_email']=$u ? $u->user_email : ''; }
        if ($category==='manager_access' || $category==='representative_access') { $u=get_userdata((int)$r['user_id']); $v['user_email']=$u ? $u->user_email : ''; }
        foreach($def['headers'] as $column) {
            if (!isset($v[$column]) && isset($r[$column])) $v[$column]=$r[$column];
        }
        if ($category==='representative_access' && isset($r['status']) && $r['status']!=='ACTIVE') continue;
        $line=[];
        foreach($def['headers'] as $column) {
            $val=(string)($v[$column] ?? '');
             
            if (preg_match('/^[=+@]/',$val)) $val="'".$val;
            $line[]=$val;
        }
        fputcsv($f,$line);
    }
    rewind($f); $output=stream_get_contents($f); fclose($f); return $output;
}
function woodcrest_v24_download_csv($cat,$template) {
    $defs=woodcrest_v24_categories(); if (!isset($defs[$cat])) throw new Exception('Unknown category.');
    $f=fopen('php://temp','w+');
    fputcsv($f,$defs[$cat]['headers']);
    if ($template) fputcsv($f,woodcrest_v24_template_row($cat));
    rewind($f); $out=stream_get_contents($f); fclose($f); return $out;
}
function woodcrest_v24_csv_permission($category) {
    if (!isset(woodcrest_v24_categories()[$category])) return false;
    if (current_user_can('manage_options')) return true;
     
    return function_exists('woodcrest_fe_is_property_manager')
        && woodcrest_fe_is_property_manager(get_current_user_id())
        && in_array($category, ['suites','utility_accounts','meters','occupancies','meter_responsibilities','readings','utility_costs','nnn_expenses'], true)
        && count(woodcrest_fe_manager_property_ids(get_current_user_id())) > 0;
}

function woodcrest_v24_row_property($category,$data) {
    global $wpdb;
    foreach (['properties','suites','utility_accounts','meters','manager_access','nnn_reconciliations'] as $cat) {
        if ($cat===$category) return (int)($data['property_id']??0);
    }
    $lookup = function($table,$id_column,$id,$property_column='property_id') use($wpdb) {
        if (!$id) return 0;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT `{$property_column}` FROM `{$wpdb->prefix}wc_{$table}` WHERE `{$id_column}`=%d",(int)$id));
    };
    if (in_array($category,['occupancies','representative_access'],true)) return $lookup('suite','suite_id',$data['suite_id']??0);
    if ($category==='meter_responsibilities' || $category==='readings') return $lookup('meter','meter_id',$data['meter_id']??0);
    if ($category==='utility_costs') return $lookup('utility_account','utility_account_id',$data['utility_account_id']??0);
    if ($category==='nnn_expenses') return $lookup('nnn_reconciliation','nnn_reconciliation_id',$data['nnn_reconciliation_id']??0);
    return 0;
}

function woodcrest_v24_enforce_row_scope($category,$data,$old=null) {
    if (current_user_can('manage_options')) return;
    if (!woodcrest_v24_csv_permission($category)) throw new Exception('Bulk upload is not available for this form.');
    $properties=array_map('intval',woodcrest_fe_manager_property_ids(get_current_user_id()));
    $target=woodcrest_v24_row_property($category,$data);
    if (!$target || !in_array($target,$properties,true)) throw new Exception('This row references a property outside your assignments.');
    if ($old && woodcrest_v24_row_property($category,(array)$old)!==$target) throw new Exception('Cannot update a record outside your properties.');
    if ($category==='meter_responsibilities' && !empty($data['suite_id'])) {
        $suite_property=woodcrest_v24_row_property('occupancies',['suite_id'=>$data['suite_id']]);
        if ($suite_property!==$target) throw new Exception('Meter and suite must belong to the same property.');
    }
    if ($category==='occupancies' && !empty($data['business_id'])) {
        $authorized=function_exists('woodcrest_v23_business_has_manager_scope')
            && woodcrest_v23_business_has_manager_scope((int)$data['business_id'],get_current_user_id());
        if (!$authorized) {
            global $wpdb;
            $owner=(int)$wpdb->get_var($wpdb->prepare('SELECT primary_user_id FROM `'.$wpdb->prefix.'wc_tenant_business` WHERE business_id=%d',(int)$data['business_id']));
            $ever_occupied=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.$wpdb->prefix.'wc_suite_occupancy` WHERE business_id=%d',(int)$data['business_id']));
            $authorized=$owner && !$ever_occupied && (int)get_user_meta($owner,'_woodcrest_created_by_manager',true)===get_current_user_id();
        }
        if (!$authorized) throw new Exception('Business access must be managed by an administrator.');
    }
     
    if ($category==='nnn_expenses') {
        global $wpdb;
        $status=$wpdb->get_var($wpdb->prepare('SELECT status FROM `'.$wpdb->prefix.'wc_nnn_reconciliation` WHERE nnn_reconciliation_id=%d',$data['nnn_reconciliation_id']));
        if ($status && strtoupper((string)$status)!=='UNDRAFTED') throw new Exception('Drafted Triple Net expenses cannot be imported.');
    }
}

function woodcrest_v24_csv_feedback_key($category) {
    return 'woodcrest_csv_result_'.get_current_user_id().'_'.sanitize_key($category);
}

function woodcrest_v24_inline_csv($category) {
    if (!woodcrest_v24_csv_permission($category)) return '';
    $anchor='wc-bulk-'.sanitize_html_class($category);
    $url=admin_url('admin-post.php');
    $download=function($kind) use($category,$url) {
        return wp_nonce_url(add_query_arg(['action'=>'woodcrest_v24_csv_download','category'=>$category,'kind'=>$kind],$url),'woodcrest_v24_csv_download_'.$category);
    };
    $response=get_transient(woodcrest_v24_csv_feedback_key($category));
    if ($response !== false) delete_transient(woodcrest_v24_csv_feedback_key($category));
    $html='<details class="wc-inline-csv" id="'.esc_attr($anchor).'"'.($response!==false?' open':'').'>';
    $html.='<summary>Bulk CSV <span aria-hidden="true">▾</span></summary>';
    $html.='<div class="wc-inline-csv-content"><div class="wc-inline-csv-links"><a href="'.esc_url($download('template')).'">Download template</a><a href="'.esc_url($download('export')).'">Export CSV</a></div>';
    if ($response!==false) {
        $is_error=!empty($response['errors']);
        $msg=$is_error?'CSV not imported':(!empty($response['committed'])?'CSV imported':'CSV checked — no changes saved');
        $html.='<div class="wc-inline-csv-status '.($is_error?'error':'success').'" role="status"><strong>'.esc_html($msg).'</strong>';
        if (!$is_error) $html.=' <span>'.(int)($response['stats']['created']??0).' new · '.(int)($response['stats']['updated']??0).' updated</span>';
        foreach(array_slice($response['errors']??[],0,12) as $error) $html.='<div>'.esc_html($error).'</div>';
        $html.='</div>';
    }
    $html.='<form method="post" action="'.esc_url($url).'" enctype="multipart/form-data" class="wc-inline-csv-form">';
    $html.='<input type="hidden" name="action" value="woodcrest_v24_inline_import"><input type="hidden" name="wc_v24_category" value="'.esc_attr($category).'">';
    $html.='<input type="hidden" name="wc_v24_return" value="'.esc_attr(get_permalink() ?: home_url('/')).'">';
    $html.=wp_nonce_field('woodcrest_v24_inline_'.$category,'wc_v24_nonce',true,false);
    $html.='<input type="file" name="wc_v24_upload" accept=".csv,text/csv" aria-label="Choose '.esc_attr(str_replace('_',' ',$category)).' CSV" required>';
    $html.='<div class="wc-inline-csv-actions"><button type="submit" name="wc_v24_mode" value="validate">Check CSV</button>';
    $html.='<button type="submit" name="wc_v24_mode" value="import">Import CSV</button></div></form></div></details>';
    return $html;
}

add_action('admin_post_woodcrest_v24_csv_download',function(){
    $cat=sanitize_key(wp_unslash($_GET['category']??''));
    if (!woodcrest_v24_csv_permission($cat)) wp_die('Permission denied.',403);
    check_admin_referer('woodcrest_v24_csv_download_'.$cat);
    $template=(($_GET['kind']??'template')==='template');
    try {
        $scope=current_user_can('manage_options')?null:array_map('intval',woodcrest_fe_manager_property_ids(get_current_user_id()));
        $out=$template?woodcrest_v24_download_csv($cat,false):woodcrest_v24_export_csv($cat,$scope);
    } catch(Throwable $e) { wp_die(esc_html($e->getMessage())); }
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="woodcrest_'.$cat.'_'.($template?'template':'export').'.csv"');
    header('X-Content-Type-Options: nosniff');
    echo $out;
    exit;
});

add_action('admin_post_woodcrest_v24_inline_import',function(){
    $cat=sanitize_key(wp_unslash($_POST['wc_v24_category']??''));
    if (!woodcrest_v24_csv_permission($cat)) wp_die('Permission denied.',403);
    if (!isset($_POST['wc_v24_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wc_v24_nonce'])),'woodcrest_v24_inline_'.$cat)) wp_die('Invalid request.',403);
    $mode=sanitize_key(wp_unslash($_POST['wc_v24_mode']??''));
    $result=['stats'=>['created'=>0,'updated'=>0],'errors'=>[],'committed'=>false];
    try {
        if (!in_array($mode,['validate','import'],true)) throw new Exception('Choose Check CSV or Import CSV.');
        $upload=$_FILES['wc_v24_upload']??[];
        $data=woodcrest_v24_files_from_upload($upload,$cat);
        $result=woodcrest_v24_process($data,$mode==='import',hash_file('sha256',$upload['tmp_name']));
    } catch(Throwable $e) { $result['errors'][]=$e->getMessage(); }
    set_transient(woodcrest_v24_csv_feedback_key($cat),$result,5*MINUTE_IN_SECONDS);
    $return=esc_url_raw(wp_unslash($_POST['wc_v24_return']??''));
    $return=wp_validate_redirect($return,home_url('/'));
    wp_safe_redirect(add_query_arg('wc_csv_result',$cat,$return).'#wc-bulk-'.$cat);
    exit;
});

 
add_shortcode('woodcrest_admin_bulk_data', '__return_empty_string');
