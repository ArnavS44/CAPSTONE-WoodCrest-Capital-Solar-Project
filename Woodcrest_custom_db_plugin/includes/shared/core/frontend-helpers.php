<?php
if (!defined('ABSPATH')) exit;


function woodcrest_fe_tables() {
    global $wpdb, $woodcrest_db;

    $mapped = (isset($woodcrest_db) && is_object($woodcrest_db)) ? $woodcrest_db : null;
    $pick = static function ($alias, $fallback) use ($mapped) {
        if ($mapped && !empty($mapped->{$alias})) {
            return $mapped->{$alias};
        }
        return $fallback;
    };

    return [
        'users' => $pick('app_user', $wpdb->users),
        'property' => $pick('property', $wpdb->prefix . 'wc_property'),
        'suite' => $pick('suite', $wpdb->prefix . 'wc_suite'),
        'suite_occupancy' => $pick('suite_occupancy', $wpdb->prefix . 'wc_suite_occupancy'),
        'user_property_access' => $pick('user_property_access', $wpdb->prefix . 'wc_user_property_access'),
        'rate_schedule' => $pick('rate_schedule', $wpdb->prefix . 'wc_rate_schedule'),
        'utility_account' => $pick('utility_account', $wpdb->prefix . 'wc_utility_account'),
        'meter' => $pick('meter', $wpdb->prefix . 'wc_meter'),
        'meter_responsibility' => $pick('meter_responsibility', $wpdb->prefix . 'wc_meter_responsibility'),
        'utility_reading' => $pick('utility_reading', $wpdb->prefix . 'wc_utility_reading'),
        'utility_monthly_cost' => $pick('utility_monthly_cost', $wpdb->prefix . 'wc_utility_monthly_cost'),
        'invoice' => $pick('invoice', $wpdb->prefix . 'wc_invoice'),
        'invoice_line_item' => $pick('invoice_line_item', $wpdb->prefix . 'wc_invoice_line_item'),
        'nnn_reconciliation' => $pick('nnn_reconciliation', $wpdb->prefix . 'wc_nnn_reconciliation'),
        'nnn_expense' => $pick('nnn_expense', $wpdb->prefix . 'wc_nnn_expense'),
        'nnn_allocation' => $pick('nnn_allocation', $wpdb->prefix . 'wc_nnn_allocation'),
    ];
}

function woodcrest_fe_is_property_manager($user_id = 0) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) return false;
    $today = current_time('Y-m-d');
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$t['user_property_access']}
         WHERE user_id = %d
           AND access_scope = 'PROPERTY_MANAGER'
           AND (start_date IS NULL OR start_date <= %s)
           AND (end_date IS NULL OR end_date > %s)
         LIMIT 1",
        $user_id, $today, $today
    ));
}


function woodcrest_fe_effective_manager_id() {
    $current_id = get_current_user_id();
    if (!$current_id) return 0;
    if (current_user_can('manage_options')) {
        $context = woodcrest_fe_preview_context();
        if (($context['role'] ?? '') === 'manager') return (int)$context['user_id'];
    }
    return $current_id;
}


function woodcrest_fe_manager_property_ids($user_id = 0) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $user_id = absint($user_id) ?: woodcrest_fe_effective_manager_id();
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


function woodcrest_fe_manager_scope_hidden_input() {
    if (!current_user_can('manage_options')) return '';
    $manager_id = woodcrest_fe_effective_manager_id();
    if (!$manager_id || $manager_id === get_current_user_id()) return '';
    return '<input type="hidden" name="woodcrest_effective_manager_id" value="' . esc_attr($manager_id) . '">';
}

function woodcrest_fe_in_ids($value, $ids) {
    return in_array((int) $value, array_map('intval', (array) $ids), true);
}

function woodcrest_fe_placeholders($ids) {
    return implode(',', array_fill(0, count($ids), '%d'));
}

function woodcrest_fe_current_url() {
    $url = get_permalink();
    if (!$url) $url = home_url('/');
    return $url;
}

function woodcrest_fe_redirect($status, $message, $extra = []) {
    $redirect = wp_get_referer();
    if (!$redirect) $redirect = home_url('/');
    $redirect = remove_query_arg(['wc_status', 'wc_message', 'wc_edit_property', 'wc_edit_access', 'wc_edit_suite', 'wc_edit_occupancy', 'wc_edit_rate', 'wc_edit_monthly_cost', 'wc_edit_account', 'wc_edit_meter', 'wc_edit_responsibility', 'wc_view_invoice'], $redirect);
    $args = array_merge([
        'wc_status' => $status,
        'wc_message' => $message,
    ], $extra);
    wp_safe_redirect(add_query_arg($args, $redirect));
    exit;
}




function woodcrest_fe_role_home_url($role) {
    $role = sanitize_key((string) $role);
    $paths = [
        'admin'   => 'admin-user-page',
        'manager' => 'property-manager-user-page',
        'tenant'  => 'tenant-user-page',
    ];

    $path = $paths[$role] ?? '';
    $url = home_url('/');
    if ($path) {
        $page = get_page_by_path($path, OBJECT, 'page');
        $url = $page ? get_permalink($page) : home_url('/' . trim($path, '/') . '/');
    }

    
    return apply_filters('woodcrest_role_home_url', $url, $role);
}


function woodcrest_fe_role_back_url($role) {
    $fallback = woodcrest_fe_role_home_url($role);
    $referer = wp_get_referer();
    if (!$referer) return $fallback;

    $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $ref_host  = wp_parse_url($referer, PHP_URL_HOST);
    if (!$home_host || !$ref_host || strtolower($home_host) !== strtolower($ref_host)) {
        return $fallback;
    }

    return $referer;
}


function woodcrest_fe_theme_mode() {
    if (!is_user_logged_in()) return 'light';
    $mode = get_user_meta(get_current_user_id(), 'woodcrest_theme_mode', true);
    return in_array($mode, ['light', 'dark'], true) ? $mode : 'light';
}


function woodcrest_fe_header_nav_html($role) {
    $back_url = woodcrest_fe_role_back_url($role);
    $logout_url = wp_logout_url(site_url('wp-login.php'));
    $mode = woodcrest_fe_theme_mode();
    $is_dark = ($mode === 'dark');

    return '<nav class="woodcrest-header-actions" aria-label="Dashboard navigation">'
        . '<a class="woodcrest-header-nav-link" href="' . esc_url($back_url) . '"><span aria-hidden="true">←</span><span>Back</span></a>'
        . '<button type="button" class="woodcrest-header-nav-link woodcrest-theme-toggle" data-wc-theme-toggle aria-pressed="' . ($is_dark ? 'true' : 'false') . '" title="Switch Woodcrest appearance"><span class="woodcrest-theme-toggle-icon" aria-hidden="true">' . ($is_dark ? '☀' : '☾') . '</span><span class="woodcrest-theme-toggle-label">' . ($is_dark ? 'Light' : 'Dark') . '</span></button>'
        . '<a class="woodcrest-header-nav-link woodcrest-header-nav-link--logout" href="' . esc_url($logout_url) . '"><span>Sign Out</span></a>'
        . '</nav>';
}

function woodcrest_fe_notice_html() {
    if (empty($_GET['wc_message'])) return '';
    $status = (!empty($_GET['wc_status']) && $_GET['wc_status'] === 'error') ? 'error' : 'success';
    $message = sanitize_text_field(wp_unslash($_GET['wc_message']));
    return '<div class="woodcrest-fe-notice ' . esc_attr($status) . '" data-wc-flash="' . esc_attr($status) . '">' . esc_html($message) . '</div>';
}

function woodcrest_fe_form_start($action, $title = '') {
    ob_start();
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="woodcrest-fe-form">
        <input type="hidden" name="action" value="woodcrest_frontend_management">
        <input type="hidden" name="woodcrest_fe_action" value="<?php echo esc_attr($action); ?>">
        <?php wp_nonce_field('woodcrest_frontend_management', 'woodcrest_fe_nonce'); ?>
        <?php echo woodcrest_fe_manager_scope_hidden_input(); ?>
        <?php if ($title): ?><h3><?php echo esc_html($title); ?></h3><?php endif; ?>
    <?php
    return ob_get_clean();
}

function woodcrest_fe_field($label, $name, $type = 'text', $value = '', $required = false, $options = [], $attrs = '') {
    ob_start();
    ?>
    <div class="woodcrest-fe-field">
        <label><?php echo esc_html($label); ?></label>
        <?php if ($type === 'select'): ?>
            <select name="<?php echo esc_attr($name); ?>" <?php echo $required ? 'required' : ''; ?> <?php echo $attrs; ?>>
                <option value="">Select</option>
                <?php foreach ($options as $opt_value => $opt_label): ?>
                    <option value="<?php echo esc_attr($opt_value); ?>" <?php selected((string)$value, (string)$opt_value); ?>><?php echo esc_html($opt_label); ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($type === 'textarea'): ?>
            <textarea name="<?php echo esc_attr($name); ?>" rows="3" <?php echo $required ? 'required' : ''; ?> <?php echo $attrs; ?>><?php echo esc_textarea($value); ?></textarea>
        <?php else: ?>
            <input type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php echo $required ? 'required' : ''; ?> <?php echo $attrs; ?>>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


function woodcrest_fe_attrs_html($attrs = []) {
    $html = '';
    foreach ((array) $attrs as $name => $value) {
        $name = preg_replace('/[^a-zA-Z0-9_:\-]/', '', (string) $name);
        if ($name === '' || $value === null || $value === false) continue;
        if ($value === true) {
            $html .= ' ' . $name;
        } else {
            $html .= ' ' . $name . '="' . esc_attr((string) $value) . '"';
        }
    }
    return $html;
}


function woodcrest_fe_cascade_field($label, $name, $value, $required, $options, $select_attrs = [], $option_attrs = [], $placeholder = 'Select') {
    ob_start();
    ?>
    <div class="woodcrest-fe-field woodcrest-fe-field-cascade">
        <label><?php echo esc_html($label); ?></label>
        <select name="<?php echo esc_attr($name); ?>" <?php echo $required ? 'required' : ''; ?><?php echo woodcrest_fe_attrs_html($select_attrs); ?>>
            <option value=""><?php echo esc_html($placeholder); ?></option>
            <?php foreach ((array) $options as $opt_value => $opt_label):
                $attrs = $option_attrs[(string) $opt_value] ?? ($option_attrs[(int) $opt_value] ?? []);
                ?>
                <option value="<?php echo esc_attr($opt_value); ?>" <?php selected((string)$value, (string)$opt_value); ?><?php echo woodcrest_fe_attrs_html($attrs); ?>><?php echo esc_html($opt_label); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
    return ob_get_clean();
}

function woodcrest_fe_form_end($button = 'Save') {
    return '<div class="woodcrest-fe-submit"><button type="submit" class="woodcrest-fe-button">' . esc_html($button) . '</button></div></form>';
}

function woodcrest_fe_card($title, $body, $open = false) {
    $csv_result = sanitize_key(wp_unslash($_GET['wc_csv_result'] ?? ''));
    if ($csv_result !== '' && strpos($body, 'id="wc-bulk-' . $csv_result . '"') !== false) $open = true;
    return '<details class="woodcrest-card woodcrest-fe-panel" ' . ($open ? 'open' : '') . '><summary><strong>' . esc_html($title) . '</strong></summary><div class="woodcrest-fe-panel-body">' . $body . '</div></details>';
}

function woodcrest_fe_period_date_cell($value, $inferred = false) {
    $label = trim((string) $value);
    $html = '<span class="wc-period-date">' . esc_html($label === '' ? '—' : $label) . '</span>';
    if ($inferred) $html .= ' <span class="wc-period-inferred">(inferred)</span>';
    return $html;
}

function woodcrest_fe_table($headers, $rows) {
    ob_start(); ?>
    <div class="woodcrest-table-wrap">
        <table class="woodcrest-table woodcrest-fe-table">
            <thead><tr><?php foreach ($headers as $h): ?><th><?php echo esc_html($h); ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?php echo esc_attr(count($headers)); ?>">No records found.</td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr><?php foreach ($row as $cell): ?><td><?php echo wp_kses_post((string)$cell); ?></td><?php endforeach; ?></tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php return ob_get_clean();
}

 
function woodcrest_fe_property_grouped_table($headers, $rows, $property_column = 0, $opts = []) {
    $group_labels = $opts['property_labels'] ?? [];
    $date_column = $opts['date_column'] ?? -1;
    $type_column = $opts['type_column'] ?? -1;
    $status_column = $opts['status_column'] ?? -1;
    $raw_columns = array_map('intval', (array)($opts['raw_columns'] ?? []));
    $groups = [];
    foreach ($rows as $index => $cells) {
        $label = isset($group_labels[$index]) ? (string)$group_labels[$index] : html_entity_decode(wp_strip_all_tags((string)($cells[$property_column] ?? '')), ENT_QUOTES, 'UTF-8');
        if (!isset($group_labels[$index]) && !empty($opts['property_suite'])) {
            $label = explode(' — ', $label, 2)[0];
        }
        $label = trim($label) ?: 'Unassigned property';
        if (!isset($groups[$label])) $groups[$label] = [];
        $groups[$label][] = $cells;
    }
    uksort($groups, 'strnatcasecmp');
    $mode = $opts['filter_mode'] ?? '';
    $types = [];
    if ($type_column >= 0) foreach ($rows as $row) {
        $type = trim(wp_strip_all_tags((string)($row[$type_column] ?? '')));
        if ($type !== '') $types[$type] = true;
    }
    ob_start(); ?>
    <div class="wc-property-groups" data-wc-group-mode="<?php echo esc_attr($mode); ?>">
      <div class="wc-property-group-filters">
        <label><span>Search</span><input type="search" class="wc-property-group-search" placeholder="Search properties or records" aria-label="Search properties or records"></label>
        <?php if ($type_column >= 0 && $types): ?><label><span>Utility type</span><select class="wc-property-group-type"><option value="">All types</option><?php foreach (array_keys($types) as $type): ?><option value="<?php echo esc_attr(strtolower($type)); ?>"><?php echo esc_html($type); ?></option><?php endforeach; ?></select></label><?php endif; ?>
        <?php if ($date_column >= 0): ?><label><span>Order</span><select class="wc-property-group-order"><option value="newest">Most recent</option><option value="oldest">Oldest first</option></select></label><?php endif; ?>
        <?php if ($status_column >= 0): ?><label><span>Invoice status</span><select class="wc-property-group-status"><option value="">All statuses</option><option value="drafted">Drafted</option><option value="undrafted">Undrafted</option></select></label><?php endif; ?>
      </div>
      <?php if (!$rows): ?><p class="wc-property-group-empty">No records found.</p><?php endif; ?>
      <?php foreach ($groups as $label => $items): ?>
        <details class="wc-property-group">
          <summary><span class="wc-property-group-chevron" aria-hidden="true">▸</span><strong><?php echo esc_html($label); ?></strong><span class="wc-property-group-count" data-total="<?php echo count($items); ?>"><?php echo count($items); ?> <?php echo count($items)===1?'record':'records'; ?></span></summary>
          <div class="woodcrest-table-wrap"><table class="woodcrest-table woodcrest-fe-table"><thead><tr><?php foreach ($headers as $head): ?><th><?php echo esc_html($head); ?></th><?php endforeach; ?></tr></thead><tbody>
          <?php foreach ($items as $cells):
              $date = $date_column >= 0 ? trim(wp_strip_all_tags((string)($cells[$date_column] ?? ''))) : '';
              $type = $type_column >= 0 ? strtolower(trim(wp_strip_all_tags((string)($cells[$type_column] ?? '')))) : '';
              $status = $status_column >= 0 ? strtolower(trim(wp_strip_all_tags((string)($cells[$status_column] ?? '')))) : '';
          ?><tr data-date="<?php echo esc_attr($date); ?>" data-type="<?php echo esc_attr($type); ?>" data-status="<?php echo esc_attr($status); ?>"><?php foreach ($cells as $column_index => $cell): ?><td><?php echo in_array((int)$column_index, $raw_columns, true) ? (string)$cell : wp_kses_post((string)$cell); ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?></tbody></table></div>
        </details>
      <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
}

function woodcrest_fe_edit_link($param, $id, $label = 'Edit') {
    return '<a class="woodcrest-fe-link" href="' . esc_url(add_query_arg($param, (int)$id, woodcrest_fe_current_url())) . '">' . esc_html($label) . '</a>';
}

function woodcrest_fe_get_record($table, $pk, $param, $allowed_property_ids = null, $property_column = 'property_id') {
    global $wpdb;
    $id = absint($_GET[$param] ?? 0);
    if (!$id) return null;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE {$pk} = %d", $id));
    if (!$row) return null;
    if (is_array($allowed_property_ids)) {
        if (!isset($row->{$property_column}) || !woodcrest_fe_in_ids($row->{$property_column}, $allowed_property_ids)) return null;
    }
    return $row;
}

function woodcrest_fe_property_id_for_suite($suite_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return (int) $wpdb->get_var($wpdb->prepare("SELECT property_id FROM {$t['suite']} WHERE suite_id = %d", $suite_id));
}

function woodcrest_fe_property_id_for_account($account_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return (int) $wpdb->get_var($wpdb->prepare("SELECT property_id FROM {$t['utility_account']} WHERE utility_account_id = %d", $account_id));
}

function woodcrest_fe_property_id_for_meter($meter_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return (int) $wpdb->get_var($wpdb->prepare("SELECT property_id FROM {$t['meter']} WHERE meter_id = %d", $meter_id));
}

function woodcrest_fe_date_is_valid($date) {
    if ($date === null || $date === '') return true;
    $dt = DateTime::createFromFormat('!Y-m-d', (string)$date);
    return $dt && $dt->format('Y-m-d') === (string)$date;
}

function woodcrest_fe_date_range_error($start, $end, $start_required = true) {
    $start = (string)$start;
    $end = (string)$end;
    if ($start_required && $start === '') return 'Start date is required.';
    if ($start !== '' && !woodcrest_fe_date_is_valid($start)) return 'Start date is invalid.';
    if ($end !== '' && !woodcrest_fe_date_is_valid($end)) return 'End date is invalid.';
    if ($start !== '' && $end !== '' && $end < $start) return 'End date cannot be before start date.';
    return '';
}

function woodcrest_fe_ranges_overlap($table, $pk, $exclude_id, $where_sql, $where_args, $start, $end) {
    global $wpdb;
    $end_compare = $end ?: '9999-12-31';
    $sql = "SELECT 1 FROM {$table}
            WHERE {$where_sql}
              AND start_date <= %s
              AND (end_date IS NULL OR end_date >= %s)";
    $args = array_merge((array)$where_args, [$end_compare, $start]);
    if ($exclude_id) {
        $sql .= " AND {$pk} <> %d";
        $args[] = (int)$exclude_id;
    }
    $sql .= ' LIMIT 1';
    return (bool)$wpdb->get_var($wpdb->prepare($sql, $args));
}

function woodcrest_fe_sync_suite_status($suite_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $suite_id = absint($suite_id);
    if (!$suite_id) return;
    $today = current_time('Y-m-d');
    $occupied = (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$t['suite_occupancy']}
         WHERE suite_id = %d
           AND start_date <= %s
           AND (end_date IS NULL OR end_date >= %s)
         LIMIT 1",
        $suite_id, $today, $today
    ));
    $wpdb->update(
        $t['suite'],
        ['status' => $occupied ? 'OCCUPIED' : 'VACANT'],
        ['suite_id' => $suite_id]
    );
}

function woodcrest_fe_sync_suite_statuses_periodically() {
    if (get_transient('woodcrest_fe_suite_status_sync_v0418')) return;
    set_transient('woodcrest_fe_suite_status_sync_v0418', 1, HOUR_IN_SECONDS);

    global $wpdb;
    $t = woodcrest_fe_tables();
    $suite_ids = array_map('intval', $wpdb->get_col("SELECT suite_id FROM {$t['suite']}"));
    foreach ($suite_ids as $suite_id) {
        woodcrest_fe_sync_suite_status($suite_id);
    }
}
add_action('init', 'woodcrest_fe_sync_suite_statuses_periodically');

function woodcrest_fe_account_info($account_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT utility_account_id, property_id, utility_type
         FROM {$t['utility_account']}
         WHERE utility_account_id = %d",
        absint($account_id)
    ));
}

function woodcrest_fe_meter_info($meter_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT meter_id, property_id, utility_account_id, utility_type
         FROM {$t['meter']}
         WHERE meter_id = %d",
        absint($meter_id)
    ));
}

function woodcrest_fe_tenant_occupancy_covers_range($tenant_id, $suite_id, $start, $end) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    if (!$tenant_id || !$suite_id || !$start) return false;
    if ($end) {
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$t['suite_occupancy']}
             WHERE tenant_user_id = %d
               AND suite_id = %d
               AND start_date <= %s
               AND (end_date IS NULL OR end_date >= %s)
             LIMIT 1",
            $tenant_id, $suite_id, $start, $end
        ));
    }
    return (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$t['suite_occupancy']}
         WHERE tenant_user_id = %d
           AND suite_id = %d
           AND start_date <= %s
           AND end_date IS NULL
         LIMIT 1",
        $tenant_id, $suite_id, $start
    ));
}


function woodcrest_fe_tenant_property_ids($tenant_id, $allowed_property_ids = null) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $tenant_id = absint($tenant_id);
    if (!$tenant_id) return [];

    $sql = "SELECT DISTINCT s.property_id
            FROM {$t['suite_occupancy']} so
            JOIN {$t['suite']} s ON s.suite_id = so.suite_id
            WHERE so.tenant_user_id = %d";
    $args = [$tenant_id];
    if (is_array($allowed_property_ids)) {
        $allowed_property_ids = array_values(array_filter(array_map('intval', $allowed_property_ids)));
        if (!$allowed_property_ids) return [];
        $sql .= ' AND s.property_id IN (' . woodcrest_fe_placeholders($allowed_property_ids) . ')';
        $args = array_merge($args, $allowed_property_ids);
    }
    return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $args)));
}

function woodcrest_fe_tenant_is_scoped($tenant_id, $manager_id = 0) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $manager_id = absint($manager_id) ?: woodcrest_fe_effective_manager_id();
    if (!$manager_id || !get_userdata($tenant_id)) return false;

    $property_ids = woodcrest_fe_manager_property_ids($manager_id);
    if (!$property_ids) {
        return (int) get_user_meta($tenant_id, '_woodcrest_created_by_manager', true) === (int) $manager_id;
    }
    $ph = woodcrest_fe_placeholders($property_ids);
    $args = array_merge([$tenant_id], $property_ids);
    $sql = "SELECT 1
            FROM {$t['suite_occupancy']} so
            JOIN {$t['suite']} s ON s.suite_id = so.suite_id
            WHERE so.tenant_user_id = %d
              AND s.property_id IN ({$ph})
              AND (so.end_date IS NULL OR so.end_date >= CURDATE())
            LIMIT 1";
    if ($wpdb->get_var($wpdb->prepare($sql, $args))) return true;
    return (int) get_user_meta($tenant_id, '_woodcrest_created_by_manager', true) === (int) $manager_id;
}

function woodcrest_fe_manager_can_manage_record_property($property_id, $manager_id = 0) {
    $manager_id = absint($manager_id) ?: woodcrest_fe_effective_manager_id();
    return woodcrest_fe_in_ids($property_id, woodcrest_fe_manager_property_ids($manager_id));
}

