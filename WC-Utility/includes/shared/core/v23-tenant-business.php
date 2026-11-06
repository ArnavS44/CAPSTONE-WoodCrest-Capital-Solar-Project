<?php
if (!defined('ABSPATH')) exit;

function woodcrest_v23_ready() {
    return get_option('woodcrest_pm_schema_version') === '2.3.0';
}

function woodcrest_v23_business_id($user_id) {
    global $wpdb;
    $user_id = absint($user_id);
    if (!$user_id || !woodcrest_v23_ready()) return 0;
    $table = $wpdb->prefix . 'wc_tenant_business_member';
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT business_id FROM {$table} WHERE user_id = %d AND status = 'ACTIVE' LIMIT 1", $user_id
    ));
}

function woodcrest_v23_primary_tenant_id($user_id) {
    global $wpdb;
    $user_id = absint($user_id);
    $business_id = woodcrest_v23_business_id($user_id);
    if (!$business_id) return $user_id;
    $table = $wpdb->prefix . 'wc_tenant_business';
    $primary = (int)$wpdb->get_var($wpdb->prepare("SELECT primary_user_id FROM {$table} WHERE business_id = %d", $business_id));
    return $primary ?: $user_id;
}

function woodcrest_v23_suite_access_ready() {
    return woodcrest_v23_ready() && get_option('woodcrest_pm_suite_access_version') === '1';
}

 
function woodcrest_v24_access_history_table() {
    global $wpdb;
    return $wpdb->prefix . 'wc_tenant_suite_access_history';
}
 
function woodcrest_v24_suite_account_dates_ready() {
    global $wpdb;
    $table = $wpdb->prefix . 'wc_tenant_suite_access';
    return woodcrest_pm_column_exists($table, 'start_date') && woodcrest_pm_column_exists($table, 'end_date');
}
function woodcrest_v24_install_suite_account_dates() {
    if (!woodcrest_v23_suite_access_ready()) return;
    global $wpdb;
    $table = $wpdb->prefix . 'wc_tenant_suite_access';
    if (!woodcrest_pm_table_exists($table)) return;
    if (!woodcrest_pm_column_exists($table, 'start_date')) {
        if ($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN start_date DATE NULL DEFAULT NULL") === false) return;
    }
    if (!woodcrest_pm_column_exists($table, 'end_date')) {
        if ($wpdb->query("ALTER TABLE `{$table}` ADD COLUMN end_date DATE NULL DEFAULT NULL") === false) return;
    }
     
     
    $occ = $wpdb->prefix . 'wc_suite_occupancy';
    $wpdb->query("UPDATE `{$table}` sa SET sa.start_date=COALESCE((
        SELECT CASE WHEN sa.user_id=so.tenant_user_id THEN so.start_date
                    ELSE GREATEST(so.start_date, DATE(sa.created_at)) END
        FROM `{$occ}` so
        WHERE so.suite_id=sa.suite_id AND so.business_id=sa.business_id
        ORDER BY so.start_date DESC LIMIT 1
    ),DATE(sa.created_at)) WHERE sa.start_date IS NULL");
}
add_action('admin_init', 'woodcrest_v24_install_suite_account_dates');
 
function woodcrest_v24_finalize_suite_account_terms() {
    if (!woodcrest_v24_suite_account_dates_ready()) return;
    global $wpdb;
    $table=$wpdb->prefix . 'wc_tenant_suite_access';
    $today=current_time('Y-m-d');
    $ended=$wpdb->get_results($wpdb->prepare(
        "SELECT sa.*, so.tenant_user_id AS billing_user_id, so.start_date AS lease_start
         FROM {$table} sa JOIN {$wpdb->prefix}wc_suite_occupancy so ON so.occupancy_id=(
             SELECT x.occupancy_id FROM {$wpdb->prefix}wc_suite_occupancy x
             WHERE x.business_id=sa.business_id AND x.suite_id=sa.suite_id
             ORDER BY x.start_date DESC,x.occupancy_id DESC LIMIT 1)
         WHERE sa.status='ACTIVE' AND sa.end_date IS NOT NULL AND sa.end_date<%s", $today));
    foreach ((array)$ended as $item) {
        $start=(string)($item->start_date ?: $item->lease_start);
        if (!woodcrest_v24_archive_member_term((int)$item->user_id,(int)$item->business_id,
            (int)$item->suite_id,(int)$item->billing_user_id,$start,(string)$item->end_date)) continue;
        $wpdb->update($table,['status'=>'INACTIVE'],['access_id'=>(int)$item->access_id,'status'=>'ACTIVE']);
    }
}
add_action('init','woodcrest_v24_finalize_suite_account_terms', 20);

 
function woodcrest_v24_reading_member_scope_sql($viewer_id, $suite_column, $business_column, $from_expr, $through_expr) {
    if (!woodcrest_v24_suite_account_dates_ready()) return '1=1';
    global $wpdb;
    $scopes=$wpdb->prefix . 'wc_tenant_suite_access';
    $viewer_id=absint($viewer_id);
    return "EXISTS (SELECT 1 FROM {$scopes} msa
        WHERE msa.user_id={$viewer_id} AND msa.suite_id={$suite_column}
          AND msa.business_id={$business_column} AND msa.status='ACTIVE'
          AND (msa.start_date IS NULL OR msa.start_date<={$from_expr})
          AND (msa.end_date IS NULL OR msa.end_date>={$through_expr}))";
}


 
function woodcrest_v24_archive_member_term($user_id, $business_id, $suite_id, $billing_user_id, $start, $end) {
    global $wpdb;
    $table = woodcrest_v24_access_history_table();
    if (!woodcrest_pm_table_exists($table) || !$start || !$end || $start > $end) return false;
    $exists = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT history_id FROM {$table} WHERE user_id=%d AND business_id=%d AND suite_id=%d AND start_date=%s AND end_date=%s LIMIT 1",
        $user_id,$business_id,$suite_id,$start,$end
    ));
    if ($exists) return true;
    return $wpdb->insert($table, [
        'user_id'=>$user_id,'business_id'=>$business_id,'suite_id'=>$suite_id,
        'billing_user_id'=>$billing_user_id,'start_date'=>$start,'end_date'=>$end
    ]) !== false;
}

function woodcrest_v24_install_access_history() {
    if (!woodcrest_v23_suite_access_ready() || get_option('woodcrest_suite_access_history_version') === '1') return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = woodcrest_v24_access_history_table();
    dbDelta("CREATE TABLE {$table} (
        history_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        business_id bigint(20) unsigned NOT NULL,
        suite_id bigint(20) unsigned NOT NULL,
        billing_user_id bigint(20) unsigned NOT NULL,
        start_date date NOT NULL,
        end_date date NOT NULL,
        PRIMARY KEY  (history_id),
        KEY user_suite_dates (user_id,suite_id,start_date,end_date),
        KEY business_suite (business_id,suite_id)
    ) {$wpdb->get_charset_collate()};");
    if (woodcrest_pm_table_exists($table)) update_option('woodcrest_suite_access_history_version', '1');
}
add_action('admin_init','woodcrest_v24_install_access_history');

function woodcrest_v24_historical_suite_ids($user_id) {
    global $wpdb;
    $table=woodcrest_v24_access_history_table();
    if (!woodcrest_pm_table_exists($table)) return [];
    $today=current_time('Y-m-d');
    return array_values(array_unique(array_map('intval', (array)$wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT suite_id FROM {$table} WHERE user_id=%d AND start_date<=%s AND end_date<=%s",
        absint($user_id),$today,$today
    )))));
}

 
function woodcrest_v24_invoice_scope_sql($alias, $user_id) {
    global $wpdb;
    $alias = preg_replace('/[^a-zA-Z0-9_]/','',$alias);
    $user_id=absint($user_id);
    $scopes=$wpdb->prefix.'wc_tenant_suite_access';
    $members=$wpdb->prefix.'wc_tenant_business_member';
    $dated = woodcrest_v24_suite_account_dates_ready();
    $today = esc_sql(current_time('Y-m-d'));
    $membership_dates = $dated ? " AND (sa.start_date IS NULL OR sa.start_date<='{$today}') AND (sa.end_date IS NULL OR sa.end_date>='{$today}') AND (sa.start_date IS NULL OR sa.start_date<={$alias}.period_start) AND (sa.end_date IS NULL OR sa.end_date>={$alias}.period_end)" : '';
    $current="EXISTS (SELECT 1 FROM {$scopes} sa JOIN {$members} bm ON bm.business_id=sa.business_id AND bm.user_id=sa.user_id AND bm.status='ACTIVE' WHERE sa.user_id={$user_id} AND sa.business_id={$alias}.business_id AND sa.suite_id={$alias}.suite_id AND sa.status='ACTIVE' {$membership_dates})";
    $archive=woodcrest_v24_access_history_table();
    if (!woodcrest_pm_table_exists($archive)) return '('.$current.')';
    $historic="EXISTS (SELECT 1 FROM {$archive} ah WHERE ah.user_id={$user_id} AND ah.business_id={$alias}.business_id AND ah.suite_id={$alias}.suite_id AND ah.start_date<='{$today}' AND ah.end_date<='{$today}' AND {$alias}.period_start >= ah.start_date AND {$alias}.period_end <= ah.end_date)";
    return '(' . $current . ' OR ' . $historic . ')';
}

function woodcrest_v23_visible_suite_ids($user_id) {
    global $wpdb;
    $user_id = absint($user_id);
    if (!$user_id || !woodcrest_v23_suite_access_ready()) return [];
    $scopes = $wpdb->prefix . 'wc_tenant_suite_access';
    $members = $wpdb->prefix . 'wc_tenant_business_member';
    $occupancy = $wpdb->prefix . 'wc_suite_occupancy';
    $today = current_time('Y-m-d');
    $dated_sql = woodcrest_v24_suite_account_dates_ready() ? $wpdb->prepare(
        "AND (sa.start_date IS NULL OR sa.start_date <= %s) AND (sa.end_date IS NULL OR sa.end_date >= %s)", $today, $today) : '';
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT sa.suite_id FROM {$scopes} sa
         JOIN {$members} bm ON bm.user_id = sa.user_id AND bm.business_id = sa.business_id AND bm.status = 'ACTIVE'
         JOIN {$occupancy} so ON so.business_id = sa.business_id AND so.suite_id = sa.suite_id
         WHERE sa.user_id = %d AND sa.status = 'ACTIVE'
           AND so.start_date <= %s AND (so.end_date IS NULL OR so.end_date >= %s)
           {$dated_sql}", $user_id, $today, $today
    ));
    return array_values(array_unique(array_map('intval', (array)$ids)));
}

function woodcrest_v23_tenant_suite_filter_sql($column = 'suite_id') {
    if (current_user_can('manage_options') || !woodcrest_v23_ready()) return '';
    $column = preg_replace('/[^a-zA-Z0-9_.]/', '', (string)$column);
    $ids = woodcrest_v23_visible_suite_ids(get_current_user_id());
    if (!$ids || !$column) return ' AND 1 = 0';
    return " AND {$column} IN (" . implode(',', $ids) . ')';
}

function woodcrest_v23_user_can_access_suite($user_id, $business_id, $suite_id) {
    $business_id = absint($business_id);
    $suite_id = absint($suite_id);
    if (!$business_id || !$suite_id) return false;
    if (!in_array($suite_id, woodcrest_v23_visible_suite_ids($user_id), true)) return false;
    global $wpdb;
    $scopes = $wpdb->prefix . 'wc_tenant_suite_access';
    $today=current_time('Y-m-d');
    $dated_sql=woodcrest_v24_suite_account_dates_ready() ? $wpdb->prepare(
        "AND (start_date IS NULL OR start_date<=%s) AND (end_date IS NULL OR end_date>=%s)",$today,$today) : '';
    return (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$scopes} WHERE user_id = %d AND business_id = %d AND suite_id = %d AND status = 'ACTIVE' {$dated_sql} LIMIT 1",
        absint($user_id), $business_id, $suite_id
    ));
}

function woodcrest_v23_invoice_can_view($invoice, $user_id) {
    if (!$invoice) return false;
    if (!woodcrest_v23_ready()) return (int)$invoice->tenant_user_id === (int)$user_id;
    if (!woodcrest_v23_suite_access_ready()) return false;
    if (woodcrest_v23_user_can_access_suite($user_id, (int)($invoice->business_id ?? 0), (int)($invoice->suite_id ?? 0))) {
        if (!woodcrest_v24_suite_account_dates_ready()) return true;
        global $wpdb;
        $scopes=$wpdb->prefix . 'wc_tenant_suite_access';
        $current=$wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$scopes} WHERE user_id=%d AND business_id=%d AND suite_id=%d AND status='ACTIVE'
             AND (start_date IS NULL OR start_date<=%s) AND (end_date IS NULL OR end_date>=%s) LIMIT 1",
            absint($user_id),(int)$invoice->business_id,(int)$invoice->suite_id,
            (string)$invoice->period_start,(string)$invoice->period_end
        ));
        if ($current) return true;
    }
    global $wpdb;
    $history=woodcrest_v24_access_history_table();
    if (!woodcrest_pm_table_exists($history)) return false;
    return (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$history} WHERE user_id=%d AND business_id=%d AND suite_id=%d AND start_date<=%s AND end_date>=%s AND end_date<=%s LIMIT 1",
        absint($user_id), (int)($invoice->business_id ?? 0), (int)($invoice->suite_id ?? 0),
        (string)($invoice->period_start ?? ''),(string)($invoice->period_end ?? ''),current_time('Y-m-d')
    ));
}

 
function woodcrest_v24_sync_lease_membership_dates($business_id, $suite_id, $lease_end, $billing_user_id) {
    if (!woodcrest_v24_suite_account_dates_ready() || !$lease_end) return true;
    global $wpdb;
    $scopes=$wpdb->prefix . 'wc_tenant_suite_access';
    $today=current_time('Y-m-d');
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT access_id,user_id,start_date,end_date FROM {$scopes}
         WHERE business_id=%d AND suite_id=%d AND status='ACTIVE'",
        $business_id,$suite_id));
    foreach ((array)$rows as $item) {
        $start=(string)($item->start_date ?: $lease_end);
        $until=($item->end_date && $item->end_date < $lease_end) ? (string)$item->end_date : $lease_end;
         
        if ($item->end_date && $item->end_date >= $today && $item->end_date > $until) {
            if ($wpdb->delete(woodcrest_v24_access_history_table(), [
                'user_id'=>(int)$item->user_id,'business_id'=>$business_id,'suite_id'=>$suite_id,
                'start_date'=>$start,'end_date'=>(string)$item->end_date
            ]) === false) return false;
        }
        if ($start <= $until && !woodcrest_v24_archive_member_term((int)$item->user_id,
            $business_id,$suite_id,$billing_user_id,$start,$until)) return false;
        if ($wpdb->update($scopes,[
            'end_date'=>$until,'status'=>($until < $today ? 'INACTIVE' : 'ACTIVE')
        ],['access_id'=>(int)$item->access_id]) === false) return false;
    }
    return true;
}

function woodcrest_v23_grant_primary_suite($business_id, $suite_id) {
    global $wpdb;
    if (!woodcrest_v23_suite_access_ready()) return false;
    $business_id = absint($business_id);
    $suite_id = absint($suite_id);
    $b = $wpdb->prefix . 'wc_tenant_business';
    $scopes = $wpdb->prefix . 'wc_tenant_suite_access';
    $user_id = (int)$wpdb->get_var($wpdb->prepare("SELECT primary_user_id FROM {$b} WHERE business_id = %d", $business_id));
    if (!$user_id || !$suite_id) return false;
    $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT access_id FROM {$scopes} WHERE business_id = %d AND user_id = %d AND suite_id = %d", $business_id, $user_id, $suite_id));
    $occ=$wpdb->prefix . 'wc_suite_occupancy';
    $lease_start=$wpdb->get_var($wpdb->prepare(
        "SELECT start_date FROM {$occ} WHERE business_id=%d AND suite_id=%d ORDER BY start_date DESC LIMIT 1",
        $business_id,$suite_id));
    if ($exists) {
        $values=['status'=>'ACTIVE','created_at'=>current_time('mysql')];
        if (woodcrest_v24_suite_account_dates_ready()) {
            $values['start_date']=$lease_start ?: current_time('Y-m-d');
            $values['end_date']=null;
        }
        return $wpdb->update($scopes,$values,['access_id'=>$exists]) !== false;
    }
    $values=['business_id'=>$business_id,'user_id'=>$user_id,'suite_id'=>$suite_id,
        'status'=>'ACTIVE','created_at'=>current_time('mysql')];
    if (woodcrest_v24_suite_account_dates_ready()) {
        $values['start_date']=$lease_start ?: current_time('Y-m-d');
        $values['end_date']=null;
    }
    return $wpdb->insert($scopes,$values) !== false;
}

function woodcrest_v23_ensure_business($user_id, $name = '') {
    global $wpdb;
    if (!woodcrest_v23_ready()) return 0;
    $user_id = absint($user_id);
    $existing = woodcrest_v23_business_id($user_id);
    if ($existing) return $existing;
    $user = get_userdata($user_id);
    if (!$user) return 0;
    $name = trim((string)$name) ?: ($user->display_name ?: $user->user_login);
    $business = $wpdb->prefix . 'wc_tenant_business';
    $members = $wpdb->prefix . 'wc_tenant_business_member';
    $wpdb->query('START TRANSACTION');
    $ok = $wpdb->insert($business, ['business_name' => $name, 'primary_user_id' => $user_id, 'created_at' => current_time('mysql')]);
    $id = $ok ? (int)$wpdb->insert_id : 0;
    if (!$id || $wpdb->insert($members, ['business_id' => $id, 'user_id' => $user_id, 'status' => 'ACTIVE', 'created_at' => current_time('mysql')]) === false) {
        $wpdb->query('ROLLBACK');
        return 0;
    }
    $wpdb->query('COMMIT');
    return $id;
}

function woodcrest_v23_business_has_manager_scope($business_id, $manager_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $business_id = absint($business_id);
    $managed = woodcrest_fe_manager_property_ids($manager_id);
    if (!$business_id || !$managed) return false;
    $today = current_time('Y-m-d');
    $ph = woodcrest_fe_placeholders($managed);
    $sql = "SELECT COUNT(DISTINCT s.property_id) FROM {$t['suite_occupancy']} so
            JOIN {$t['suite']} s ON s.suite_id = so.suite_id
            WHERE so.business_id = %d AND (so.end_date IS NULL OR so.end_date >= %s)
              AND s.property_id NOT IN ({$ph})";
    $outside = (int)$wpdb->get_var($wpdb->prepare($sql, array_merge([$business_id, $today], $managed)));
    if ($outside) return false;
    return (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$t['suite_occupancy']} so JOIN {$t['suite']} s ON s.suite_id = so.suite_id
         WHERE so.business_id = %d AND (so.end_date IS NULL OR so.end_date >= %s)
           AND s.property_id IN ({$ph}) LIMIT 1",
        array_merge([$business_id, $today], $managed)
    ));
}

function woodcrest_v23_prorated_monthly($monthly, $start, $end, $active_start = '', $active_end = '') {
    $monthly = (float)$monthly;
    if (!woodcrest_fe_date_is_valid($start) || !woodcrest_fe_date_is_valid($end) || $end < $start) return 0.0;
    $from = max($start, $active_start ?: $start);
    $through = min($end, $active_end ?: $end);
    if ($from > $through) return 0.0;
    $cursor = new DateTimeImmutable(substr($from, 0, 7) . '-01');
    $last = new DateTimeImmutable($through);
    $total = 0.0;
    while ($cursor <= $last) {
        $last_of_month = $cursor->modify('last day of this month');
        $within_start = max($from, $cursor->format('Y-m-d'));
        $within_end = min($through, $last_of_month->format('Y-m-d'));
        $days = (int)(new DateTimeImmutable($within_start))->diff((new DateTimeImmutable($within_end)))->days + 1;
        $total += $monthly * $days / (int)$cursor->format('t');
        $cursor = $cursor->modify('first day of next month');
    }
    return round($total, 2);
}

function woodcrest_v23_business_in_property($business_id, $property_id) {
    global $wpdb;
    if (!woodcrest_v23_ready() || !$business_id || !$property_id) return false;
    $t = woodcrest_fe_tables();
    $today = current_time('Y-m-d');
    return (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$t['suite_occupancy']} so
         JOIN {$t['suite']} s ON s.suite_id = so.suite_id
         WHERE so.business_id = %d AND s.property_id = %d
           AND (so.end_date IS NULL OR so.end_date >= %s)
         LIMIT 1",
        absint($business_id), absint($property_id), $today
    ));
}

function woodcrest_v23_business_occupies_suite($business_id, $suite_id) {
    global $wpdb;
    $occ = $wpdb->prefix . 'wc_suite_occupancy';
    $today = current_time('Y-m-d');
    return (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$occ} WHERE business_id = %d AND suite_id = %d
         AND (end_date IS NULL OR end_date >= %s) LIMIT 1",
        absint($business_id), absint($suite_id), $today
    ));
}

 
function woodcrest_v23_business_management_html() {
    if (!woodcrest_v23_suite_access_ready())
        return '<div class="woodcrest-fe-notice error">Suite access must be configured by an administrator first.</div>';
    global $wpdb;
    $t = woodcrest_fe_tables();
    $admin = current_user_can('manage_options');
    $manager_id = woodcrest_fe_effective_manager_id();
    $allowed = $admin ? array_map('intval', (array)$wpdb->get_col("SELECT property_id FROM {$t['property']}")) : woodcrest_fe_manager_property_ids($manager_id);
    if (!$allowed) return '<div class="woodcrest-fe-notice">No accessible properties.</div>';
    $ids = implode(',', array_map('intval',$allowed));
    $today = current_time('Y-m-d');
    $occupied = $wpdb->get_results($wpdb->prepare(
        "SELECT so.business_id, so.tenant_user_id, s.suite_id, s.property_id, s.suite_label, p.property_name
         FROM {$t['suite_occupancy']} so
         JOIN {$t['suite']} s ON s.suite_id=so.suite_id
         JOIN {$t['property']} p ON p.property_id=s.property_id
         WHERE s.property_id IN ({$ids}) AND so.business_id IS NOT NULL
           AND so.start_date <= %s AND (so.end_date IS NULL OR so.end_date >= %s)
         ORDER BY p.property_name,s.suite_label", $today,$today));
    $property_options=[]; $suite_options=[]; $suite_attrs=[];
    foreach ((array)$occupied as $item) {
        $property_options[(int)$item->property_id] = (string)$item->property_name;
        $suite_options[(int)$item->suite_id] = (string)$item->suite_label;
        $suite_attrs[(int)$item->suite_id] = ['data-wc-property'=>(int)$item->property_id];
    }
    if (!$occupied) return '<div class="woodcrest-fe-notice">Assign a primary tenant to a suite first, then grant additional accounts access here.</div>';
    $available = get_users(['fields'=>['ID','display_name','user_email'],'number'=>1000,'orderby'=>'display_name']);
    $user_options=[];
    foreach ($available as $item) {
        $user = get_userdata($item->ID);
        if (!$user) continue;
        if (user_can($user,'manage_options') || woodcrest_fe_is_property_manager($user->ID)) continue;
        if (!$admin && (int)get_user_meta($user->ID,'_woodcrest_created_by_manager',true) !== $manager_id) continue;
        $user_options[(int)$user->ID] = (string)$user->display_name.' — '.(string)$user->user_email;
    }
    $form = woodcrest_fe_form_start('grant_suite_account');
    $form .= woodcrest_fe_cascade_field('Property','property_id','',true,$property_options,['data-wc-role'=>'property'],[],'Select property');
    $form .= woodcrest_fe_cascade_field('Suite','suite_id','',true,$suite_options,['data-wc-role'=>'suite','data-wc-require-property'=>'1'],$suite_attrs,'Select property first');
    $form .= woodcrest_fe_field('Tenant account','user_id','select','',true,$user_options);
    $form .= woodcrest_fe_form_end('Add account to suite');
    $html = woodcrest_fe_card('Give a tenant account suite access',$form,true);
    $scope_table = $wpdb->prefix . 'wc_tenant_suite_access';
    if (!woodcrest_v24_suite_account_dates_ready()) {
        return $html . '<div class="woodcrest-fe-notice error">Suite account dates are not installed yet. Open WordPress Admin once, then reload this page.</div>';
    }
    $business_table = $wpdb->prefix . 'wc_tenant_business';
    $rows = $wpdb->get_results(
        "SELECT sa.access_id, sa.suite_id, sa.user_id, sa.business_id, sa.start_date AS member_start,
                sa.end_date AS member_end, sa.status AS member_status, sa.created_at,
                s.property_id, p.property_name, s.suite_label, u.display_name, u.user_email,
                b.primary_user_id, so.occupancy_id, so.tenant_user_id AS occupancy_tenant_id,
                so.start_date AS occupancy_start, so.end_date AS occupancy_end
         FROM {$scope_table} sa
         JOIN {$t['suite']} s ON s.suite_id=sa.suite_id
         JOIN {$t['property']} p ON p.property_id=s.property_id
         JOIN {$t['users']} u ON u.ID=sa.user_id
         JOIN {$business_table} b ON b.business_id=sa.business_id
         JOIN {$t['suite_occupancy']} so ON so.occupancy_id=(
             SELECT so2.occupancy_id FROM {$t['suite_occupancy']} so2
             WHERE so2.suite_id=sa.suite_id AND so2.business_id=sa.business_id
             ORDER BY so2.start_date DESC, so2.occupancy_id DESC LIMIT 1
         )
         WHERE s.property_id IN ({$ids})
         ORDER BY p.property_name,s.suite_label,u.display_name"
    );
    $edit_id = absint($_GET['wc_edit_suite_account'] ?? 0);
    $edit = null;
    $cells = [];
    foreach ((array)$rows as $r) {
        $start = (string)($r->member_start ?: ($r->user_id == $r->occupancy_tenant_id ? $r->occupancy_start : substr($r->created_at,0,10)));
        $end = (string)($r->member_end ?: $r->occupancy_end ?: '');
        $active = $r->member_status === 'ACTIVE' && $start <= $today && (!$end || $end >= $today)
            && $r->occupancy_start <= $today && (!$r->occupancy_end || $r->occupancy_end >= $today);
        $occupancy = esc_html($start) . ' – ' . esc_html($end ?: 'Present');
        if (!$active && $r->member_status !== 'ACTIVE') $occupancy = esc_html($start) . ' – ' . esc_html($end ?: 'Ended');
        elseif (!$active) $occupancy .= ' <small>(Ended or upcoming)</small>';
        $link = $r->member_status === 'ACTIVE' ? woodcrest_fe_edit_link('wc_edit_suite_account', $r->access_id) : '<span>Ended</span>';
        $cells[] = [esc_html($r->property_name),esc_html($r->suite_label),esc_html($r->display_name),esc_html($r->user_email),$occupancy,$link];
        if ($edit_id && $edit_id === (int)$r->access_id && $r->member_status === 'ACTIVE') $edit = $r;
    }
    if ($edit) {
        $start = (string)($edit->member_start ?: $edit->occupancy_start);
        $end = (string)($edit->member_end ?: '');
        $edit_form = woodcrest_fe_form_start('update_suite_account_dates');
        $edit_form .= '<input type="hidden" name="access_id" value="'.esc_attr((int)$edit->access_id).'">';
        $edit_form .= woodcrest_fe_field('Tenant account', 'account_name', 'text', $edit->display_name, false, [], 'readonly');
        $edit_form .= woodcrest_fe_field('Start date', 'start_date', 'date', $start, true);
        $edit_form .= woodcrest_fe_field('End date', 'end_date', 'date', $end);
        if ((int)$edit->user_id === (int)$edit->occupancy_tenant_id) {
            $others = [];
            foreach ($rows as $r) {
                if ((int)$r->suite_id !== (int)$edit->suite_id || (int)$r->business_id !== (int)$edit->business_id || (int)$r->user_id === (int)$edit->user_id) continue;
                if ($r->member_status !== 'ACTIVE' || ($r->member_start && $r->member_start > $today) || ($r->member_end && $r->member_end <= $today)) continue;
                $others[(int)$r->user_id] = $r->display_name;
            }
            if ($others) {
                $edit_form .= woodcrest_fe_field('New billing contact (if ending current contact)', 'replacement_user_id', 'select', '', false, [''=>'Select when ending'] + $others);
            }
        }
        $edit_form .= woodcrest_fe_form_end('Save occupancy dates');
        $html .= woodcrest_fe_card('Edit Suite Account — ' . esc_html($edit->display_name), $edit_form, true);
    }
    $html .= '<section class="woodcrest-card woodcrest-card-wide"><div class="woodcrest-card-header"><h2>Suite Accounts</h2></div>'
        .woodcrest_fe_property_grouped_table(['Property','Suite','Account','Email','Occupancy','Action'], $cells, 0, ['date_column'=>4]).'</section>';
    return $html;
}

function woodcrest_v23_business_access_table_html($rows) {
    $headers = ['Business ID','Business','Suite','Representative','Email','Role','Access','Action'];
    $html = '<div class="woodcrest-table-wrap"><table class="woodcrest-table woodcrest-fe-table"><thead><tr>';
    foreach ($headers as $heading) $html .= '<th>' . esc_html($heading) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $cells) {
        $html .= '<tr>';
        foreach ($cells as $index => $cell) {
            $html .= '<td>' . ($index === 7 ? $cell : wp_kses_post((string)$cell)) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

function woodcrest_v23_real_name($user) {
    if (!$user) return '';
    $first = trim((string)get_user_meta($user->ID, 'first_name', true));
    $last = trim((string)get_user_meta($user->ID, 'last_name', true));
    $full = trim($first . ' ' . $last);
    if ($full !== '') return $full;
    if (isset($user->full_name) && trim((string)$user->full_name) !== '') return trim((string)$user->full_name);
    $display = trim((string)$user->display_name);
    return $display !== '' ? $display : (string)$user->user_login;
}

add_shortcode('woodcrest_admin_tenant_businesses', function () {
    if (!current_user_can('manage_options')) return '<div class="woodcrest-card">Administrator access required.</div>';
    return woodcrest_fe_admin_module_wrap(woodcrest_v23_business_management_html());
});

add_shortcode('woodcrest_admin_suite_accounts', function () {
    if (!current_user_can('manage_options')) return '<div class="woodcrest-card">Administrator access required.</div>';
    return woodcrest_fe_admin_module_wrap(woodcrest_v23_business_management_html());
});
