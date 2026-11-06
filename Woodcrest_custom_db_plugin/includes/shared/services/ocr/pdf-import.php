<?php
if (!defined('ABSPATH')) exit;

 
function woodcrest_ocr_table() {
    global $wpdb;
    return $wpdb->prefix . 'wc_supplier_invoice';
}

function woodcrest_ocr_charge_table() {
    global $wpdb;
    return $wpdb->prefix . 'wc_supplier_invoice_charge';
}

function woodcrest_ocr_categories() {
    return [
        'WATER' => 'Water', 'ELECTRICITY' => 'Electricity', 'SEWER' => 'Sewer / Sewage',
        'IRRIGATION' => 'Irrigation', 'DRAINAGE' => 'Drainage / Stormwater',
        'MAINTENANCE' => 'Maintenance', 'LANDSCAPING' => 'Landscaping',
        'INSURANCE' => 'Insurance', 'PROPERTY_TAX' => 'Property Tax', 'OTHER' => 'Other',
    ];
}
function woodcrest_ocr_allocations() {
    return [
        'TENANT_REFERENCE' => 'Tenant billback reference',
        'CAM_REFERENCE' => 'CAM reference',
        'OWNER_REFERENCE' => 'Owner expense',
        'UNASSIGNED' => 'Unassigned',
    ];
}

function woodcrest_ocr_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = woodcrest_ocr_table();
    $sql = "CREATE TABLE {$table} (
        supplier_invoice_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        property_id bigint(20) unsigned NOT NULL,
        created_by bigint(20) unsigned NOT NULL,
        reviewed_by bigint(20) unsigned DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'DRAFT',
        source_sha256 char(64) NOT NULL,
        original_filename varchar(255) NOT NULL,
        source_pdf longblob NOT NULL,
        invoice_json longtext NOT NULL,
        ocr_method varchar(32) NOT NULL DEFAULT 'NONE',
        created_at datetime NOT NULL,
        reviewed_at datetime DEFAULT NULL,
        PRIMARY KEY  (supplier_invoice_id),
        KEY property_status (property_id,status),
        KEY source_hash (source_sha256)
    ) " . $wpdb->get_charset_collate() . ';';
    dbDelta($sql);
     
    $charge_table = woodcrest_ocr_charge_table();
    $charge_sql = "CREATE TABLE {$charge_table} (
        supplier_charge_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        supplier_invoice_id bigint(20) unsigned NOT NULL,
        line_number smallint(5) unsigned NOT NULL,
        charge_category varchar(32) NOT NULL DEFAULT 'OTHER',
        description varchar(190) NOT NULL DEFAULT '',
        amount decimal(13,2) NOT NULL DEFAULT 0.00,
        allocation varchar(24) NOT NULL DEFAULT 'UNASSIGNED',
        expense_year smallint(5) unsigned NOT NULL,
        meter_number varchar(64) NOT NULL DEFAULT '',
        PRIMARY KEY  (supplier_charge_id),
        UNIQUE KEY invoice_line (supplier_invoice_id,line_number),
        KEY expense_type_year (expense_year,allocation,charge_category)
    ) " . $wpdb->get_charset_collate() . ';';
    dbDelta($charge_sql);
    if (woodcrest_fe_table_exists($table) && woodcrest_fe_table_exists($charge_table)) {
        update_option('woodcrest_ocr_schema_version', '2.4.0-rc7');
    }
}

register_activation_hook(WCPM_DIR . 'wc-utility.php', 'woodcrest_ocr_install');
add_action('init', function () {
    if (is_admin() && current_user_can('manage_options') && get_option('woodcrest_ocr_schema_version') !== '2.4.0-rc7') {
        woodcrest_ocr_install();
    }
});

function woodcrest_ocr_allowed_property_ids() {
    if (!is_user_logged_in()) return [];
    if (current_user_can('manage_options')) {
        global $wpdb;
        $t = woodcrest_fe_tables();
        return array_map('intval', (array) $wpdb->get_col("SELECT property_id FROM {$t['property']} ORDER BY property_id"));
    }
    if (!woodcrest_fe_is_property_manager()) return [];
    return array_map('intval', woodcrest_fe_manager_property_ids(get_current_user_id()));
}

function woodcrest_ocr_has_property_access($property_id) {
    return $property_id > 0 && in_array((int) $property_id, woodcrest_ocr_allowed_property_ids(), true);
}

function woodcrest_ocr_authorized_ajax($property_id = 0) {
    if (!is_user_logged_in() || (!current_user_can('manage_options') && !woodcrest_fe_is_property_manager())) {
        wp_send_json_error(['message' => 'Property manager access required.'], 403);
    }
    if (!check_ajax_referer('woodcrest_ocr_import', 'nonce', false)) {
        wp_send_json_error(['message' => 'Session expired. Refresh this page.'], 403);
    }
    if ($property_id && !woodcrest_ocr_has_property_access($property_id)) {
        wp_send_json_error(['message' => 'That property is not assigned to your account.'], 403);
    }
}

 
 

function woodcrest_ocr_date($input) {
    $input = trim((string) $input);
    if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $input, $iso)) {
        return checkdate((int)$iso[2], (int)$iso[3], (int)$iso[1]) ? sprintf('%04d-%02d-%02d', $iso[1], $iso[2], $iso[3]) : '';
    }
    if (!preg_match('~^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$~', $input, $m)) return '';
    $month = (int)$m[1]; $day = (int)$m[2]; $year = (int)$m[3];
    return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : '';
}

function woodcrest_ocr_money($text, $labels) {
    foreach ($labels as $label) {
        $pattern = '/\b' . $label . '[ \t:\-]*\$?[ \t]*([\d,]+\.\d{2})\b/im';
        if (preg_match($pattern, $text, $m)) return number_format((float) str_replace(',', '', $m[1]), 2, '.', '');
    }
    return '';
}

function woodcrest_ocr_parse($text) {
    $data = [
        'provider' => '', 'bill_number' => '', 'account_number' => '', 'service_address' => '',
        'bill_date' => '', 'due_date' => '', 'period_start' => '', 'period_end' => '',
        'water' => '', 'electricity' => '', 'water_rate'=>'', 'electric_rate'=>'',
        'total' => '', 'meter_rows' => [], 'charge_rows' => [], 'notes' => '',
    ];
    if (preg_match('/NORTH[ \t]+RICHLAND[ \t]+HILLS/i', $text)) $data['provider'] = 'City of North Richland Hills';
    elseif (preg_match('/(?:^|\n)\s*((?:(?:City|Town|County|Electric|Water|Utility|Utilities|Power)\s+)?[A-Za-z][A-Za-z &,.-]{4,70}(?:Utility|Utilities|Water|Electric|Power|Company|Department|District))\s*(?:\r?\n|$)/im', $text, $m)) $data['provider'] = trim($m[1]);
    if (preg_match('/(?:BILL|INVOICE|STATEMENT)[ \t]*(?:NUMBER|NO\.?|#|ID)[ \t:#-]*([A-Z0-9-]{3,25})/i', $text, $m)) $data['bill_number'] = $m[1];
    if (preg_match('/(?:ACCOUNT(?:\s*[-–]?\s*CUSTOMER\s*ID|\s*(?:NO\.?|NUMBER|#))|CUSTOMER\s*ID)[ \t:]*([A-Z0-9][A-Z0-9-]{3,25})/i', $text, $m)) $data['account_number'] = rtrim($m[1], ',.');
    if (preg_match('/SERVICE[ \t]+ADDRESS[ \t:]*([^\r\n]+)/i', $text, $m)) {
        $data['service_address'] = trim(preg_split('/ {3,}/', trim($m[1]))[0]);
    }
    foreach (['bill_date' => '(?:BILL(?:ING)?|INVOICE|STATEMENT)[ \t]+DATE', 'due_date' => '(?:PAYMENT[ \t]+)?DUE[ \t]+DATE|PAY[ \t]+BY'] as $key => $label) {
        if (preg_match('/(?:' . $label . ')[ \t:]*([0-9]{1,4}[\/-][0-9]{1,2}[\/-][0-9]{2,4})/i', $text, $m)) $data[$key] = woodcrest_ocr_date($m[1]);
    }
    foreach (['water' => ['WATER(?![\w])'], 'electricity' => ['ELECTRICITY', 'ELECTRIC[ \t]+CHARGES?']] as $type => $patterns) {
        $data[$type] = woodcrest_ocr_money($text, $patterns);
    }
    foreach (['water_rate'=>'WATER', 'electric_rate'=>'(?:ELECTRICITY|ELECTRIC|ENERGY)'] as $rate_key => $utility_label) {
        $unit_label = $rate_key === 'water_rate' ? '(?:GAL|GALLON|GALLONS)' : 'KWH';
        $pattern = '/\b' . $utility_label . '[ \t]+(?:UNIT[ \t]+)?RATE[ \t:=$]*\$?([0-9]{1,6}(?:\.[0-9]{1,6})?)\s*(?:\/[ \t]*' . $unit_label . '|PER[ \t]+' . $unit_label . ')\b/i';
        if (preg_match($pattern, $text, $rate_match)) $data[$rate_key] = $rate_match[1];
    }
    $data['total'] = woodcrest_ocr_money($text, ['TOTAL[ \t]+NEW[ \t]+CHARGES', 'TOTAL[ \t]+AMOUNT[ \t]+DUE', 'AMOUNT[ \t]+DUE', 'CURRENT[ \t]+CHARGES', 'TOTAL[ \t]+DUE', 'CURRENT[ \t]+BILL', 'AMOUNT[ \t]+PAYABLE']);
     
    if (preg_match_all('/(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/-]\d{1,2}[\/-]\d{4})\s*(?:[-–]|\bto\b|\bthrough\b)\s*(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/-]\d{1,2}[\/-]\d{4})/i', $text, $p, PREG_SET_ORDER)) {
        foreach ($p as $entry) {
            $a = woodcrest_ocr_date($entry[1]);
            $b = woodcrest_ocr_date($entry[2]);
            if ($a && $b && $a <= $b) { $data['period_start'] = $a; $data['period_end'] = $b; break; }
        }
    }
    foreach (preg_split('/\R/', $text) as $line) {
        if (!preg_match('/^\s*(\d{7,12})\s+(WA|IR|IRRIGATION|EL|ELECTRIC|WATER|ELECTRICITY)\b/i', $line, $m)) continue;
        $row = ['number' => $m[1], 'type' => strtoupper($m[2]), 'previous' => '', 'current' => '', 'usage' => ''];
        if (preg_match('/(\d{1,3})\s+([\d,]{3,12})\s+([\d,]{3,12})\s+([\d,]{1,12})\s*$/', $line, $numbers)) {
            $row['current'] = str_replace(',', '', $numbers[2]);
            $row['previous'] = str_replace(',', '', $numbers[3]);
            $row['usage'] = str_replace(',', '', $numbers[4]);
        }
        $data['meter_rows'][] = $row;
    }
     
    $labels = [
        'WATER' => ['WATER', 'WATER SERVICE', 'WATER USAGE', 'WATER CHARGES'], 'ELECTRICITY' => ['ELECTRICITY', 'ELECTRIC', 'ELECTRIC SERVICE', 'ENERGY CHARGE', 'ELECTRIC USAGE'],
        'SEWER' => ['SEWER', 'SEWAGE', 'WASTEWATER', 'SEWER SERVICE'], 'IRRIGATION' => ['IRRIGATION', 'IRRIGATION WATER'],
        'DRAINAGE' => ['DRAINAGE', 'STORMWATER', 'STORM WATER', 'DRAINAGE FEE'],
        'MAINTENANCE' => ['MAINTENANCE CHARGE', 'MAINTENANCE SERVICE', 'COMMON AREA MAINTENANCE'],
        'LANDSCAPING' => ['LANDSCAPING', 'LANDSCAPE SERVICE'],
        'INSURANCE' => ['INSURANCE PREMIUM', 'PROPERTY INSURANCE'], 'PROPERTY_TAX' => ['PROPERTY TAX', 'REAL ESTATE TAX'],
    ];
    foreach ($labels as $category => $names) {
        $quoted = array_map(static fn($name) => preg_quote($name, '/'), $names);
        $rx = '/\b(?:' . implode('|', $quoted) . ')\b[ \t:]*\$?[ \t]*([\d,]+\.\d{2})(?![\d])/im';
        if (!preg_match_all($rx, $text, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
        $seen_lines = [];
        foreach ($hits as $hit) {
            if (count($data['charge_rows']) >= 60) break;
             
            if ($category === 'WATER') {
                $prefix = substr($text, 0, $hit[0][1]);
                $last_break = strrpos($prefix, "\n");
                $before = substr($prefix, $last_break === false ? 0 : $last_break + 1);
                if (preg_match('/(?:STORM|WASTE|IRRIGATION)[ \t]*$/i', $before)) continue;
            }
            $line_number = substr_count(substr($text, 0, $hit[0][1]), "\n");
            if (isset($seen_lines[$line_number])) continue;
            $seen_lines[$line_number] = true;
            $data['charge_rows'][] = [
                'category' => $category, 'description' => woodcrest_ocr_categories()[$category],
                'amount' => number_format((float)str_replace(',', '', $hit[1][0]), 2, '.', ''),
                'allocation' => in_array($category, ['IRRIGATION', 'DRAINAGE', 'LANDSCAPING', 'MAINTENANCE'], true) ? 'CAM_REFERENCE'
                    : (in_array($category, ['WATER', 'ELECTRICITY', 'SEWER'], true) ? 'TENANT_REFERENCE' : 'UNASSIGNED'),
                'expense_year' => $data['period_start'] ? substr($data['period_start'], 0, 4) : '',
                'meter_number' => '',
            ];
        }
    }
    foreach (['WATER'=>'water','ELECTRICITY'=>'electricity'] as $category=>$field) {
        $amount = 0; $exists = false;
        foreach ($data['charge_rows'] as $row) if ($row['category'] === $category) {
            $amount += (float)$row['amount']; $exists = true;
        }
        $data[$field] = $exists ? number_format($amount,2,'.','') : '';
    }
     
     
    foreach ($data['charge_rows'] as &$charge) {
        $expected = $charge['category'] === 'IRRIGATION' ? ['IR', 'IRRIGATION']
            : ($charge['category'] === 'WATER' ? ['WA', 'WATER']
            : ($charge['category'] === 'ELECTRICITY' ? ['EL', 'ELECTRIC', 'ELECTRICITY'] : []));
        foreach ($data['meter_rows'] as $meter) {
            if (in_array($meter['type'], $expected, true)) {
                $charge['meter_number'] = $meter['number'];
                break;
            }
        }
    }
    unset($charge);
    return $data;
}

function woodcrest_ocr_draft($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . woodcrest_ocr_table() . ' WHERE supplier_invoice_id = %d', $id));
}

function woodcrest_ocr_ajax_extract() {
    $property_id = absint($_POST['property_id'] ?? 0);
    woodcrest_ocr_authorized_ajax($property_id);
    if (!$property_id) wp_send_json_error(['message' => 'Choose a property.'], 400);
    $upload = $_FILES['supplier_pdf'] ?? null;
    if (!$upload || !isset($upload['error']) || (int) $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        wp_send_json_error(['message' => 'Please select a PDF file.'], 400);
    }
    $size = (int) $upload['size'];
    if (!$size || $size > 6 * 1024 * 1024) wp_send_json_error(['message' => 'PDF must be 6 MB or smaller.'], 400);
    $tmp = $upload['tmp_name'];
    $file = fopen($tmp, 'rb');
    $signature = $file ? fread($file, 5) : '';
    if ($file) fclose($file);
    if ($signature !== '%PDF-') wp_send_json_error(['message' => 'The file is not a valid PDF.'], 400);
    $bytes = file_get_contents($tmp);
    if ($bytes === false) wp_send_json_error(['message' => 'Could not read this PDF.'], 400);
    $hash = hash('sha256', $bytes);
    global $wpdb;
    $table = woodcrest_ocr_table();
    $duplicate = $wpdb->get_var($wpdb->prepare("SELECT supplier_invoice_id FROM {$table} WHERE property_id=%d AND source_sha256=%s AND status='CONFIRMED' LIMIT 1", $property_id, $hash));
    if ($duplicate) wp_send_json_error(['message' => 'This PDF was already confirmed for this property (record #' . (int) $duplicate . ').'], 409);
    @set_time_limit(75);
    $ocr_text = isset($_POST['ocr_text']) ? wp_unslash((string)$_POST['ocr_text']) : '';
    if (strlen($ocr_text) > 180000) wp_send_json_error(['message' => 'Recognized text was too large. Use the manual review fields.'], 400);
    $method = sanitize_key((string) ($_POST['ocr_method'] ?? 'manual'));
    if (!in_array($method, ['browser_text', 'browser_ocr', 'manual'], true)) $method = 'manual';
    if ($method === 'manual') $ocr_text = '';
    $fields = woodcrest_ocr_parse($ocr_text);
    $success = $wpdb->insert($table, [
        'property_id' => $property_id, 'created_by' => get_current_user_id(), 'status' => 'DRAFT',
        'source_sha256' => $hash, 'original_filename' => sanitize_file_name((string) $upload['name']),
        'source_pdf' => $bytes, 'invoice_json' => wp_json_encode($fields), 'ocr_method' => strtoupper($method),
        'created_at' => current_time('mysql'),
    ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    if (!$success) wp_send_json_error(['message' => 'Could not save the pending PDF. Check the database table and file size.'], 500);
    wp_send_json_success(['id' => (int) $wpdb->insert_id, 'fields' => $fields, 'ocr_method' => strtoupper($method)]);
}
add_action('wp_ajax_woodcrest_ocr_extract', 'woodcrest_ocr_ajax_extract');

function woodcrest_ocr_ajax_manual_draft() {
    $property_id = absint($_POST['property_id'] ?? 0);
    woodcrest_ocr_authorized_ajax($property_id);
    if (!$property_id) wp_send_json_error(['message' => 'Choose a property.'], 400);
    global $wpdb;
    if (!woodcrest_fe_table_exists(woodcrest_ocr_table()) || !woodcrest_fe_table_exists(woodcrest_ocr_charge_table())) {
        wp_send_json_error(['message' => 'Invoice tables are not installed. Open WordPress Admin once.'], 500);
    }
    $fields = woodcrest_ocr_parse('');
    $ok = $wpdb->insert(woodcrest_ocr_table(), [
        'property_id' => $property_id, 'created_by' => get_current_user_id(), 'status' => 'DRAFT',
        'source_sha256' => hash('sha256', wp_generate_uuid4()), 'original_filename' => 'Manual entry',
        'source_pdf' => '', 'invoice_json' => wp_json_encode($fields), 'ocr_method' => 'MANUAL',
        'created_at' => current_time('mysql'),
    ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    if (!$ok) wp_send_json_error(['message' => 'Could not create the manual entry.'], 500);
    wp_send_json_success(['id' => (int)$wpdb->insert_id, 'fields' => $fields, 'ocr_method' => 'MANUAL']);
}
add_action('wp_ajax_woodcrest_ocr_manual_draft', 'woodcrest_ocr_ajax_manual_draft');


function woodcrest_ocr_clean_fields($input) {
    if (!is_array($input)) return null;
    $out = [];
    foreach (['provider','bill_number','account_number','service_address','notes'] as $field) {
        $out[$field] = sanitize_text_field((string) ($input[$field] ?? ''));
    }
    foreach (['bill_date','due_date','period_start','period_end'] as $field) {
        $v = sanitize_text_field((string) ($input[$field] ?? ''));
        if ($v !== '' && !woodcrest_fe_date_is_valid($v)) return null;
        $out[$field] = $v;
    }
    if ($out['period_start'] && $out['period_end'] && $out['period_start'] > $out['period_end']) return null;
    foreach (['water','electricity','total'] as $field) {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value !== '' && (!is_numeric($value) || (float) $value < 0 || (float) $value > 999999999)) return null;
        $out[$field] = $value === '' ? '' : number_format((float) $value, 2, '.', '');
    }
    foreach (['water_rate','electric_rate'] as $rate_key) {
        $value = trim((string)($input[$rate_key] ?? ''));
        if ($value !== '' && (!preg_match('/^\d{1,6}(?:\.\d{1,6})?$/', $value) || (float)$value <= 0)) return null;
        $out[$rate_key] = $value;
    }
    $out['meter_rows'] = [];
    foreach (array_slice((array) ($input['meter_rows'] ?? []), 0, 15) as $row) {
        if (!is_array($row)) return null;
        $meter = preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($row['number'] ?? ''));
        if ($meter === '') continue;
        $record = ['number' => $meter, 'type' => strtoupper(sanitize_text_field((string) ($row['type'] ?? '')))];
        foreach (['previous','current','usage'] as $f) {
            $v = trim((string) ($row[$f] ?? ''));
            if ($v !== '' && (!is_numeric($v) || (float)$v < 0)) return null;
            $record[$f] = $v;
        }
        if (!in_array($record['type'], ['WA', 'WATER', 'IR', 'IRRIGATION', 'EL', 'ELECTRIC', 'ELECTRICITY'], true)) return null;
        if ($record['previous'] !== '' && $record['current'] !== '' && (float)$record['previous'] > (float)$record['current']) return null;
        $out['meter_rows'][] = $record;
    }
    return $out;
}

function woodcrest_ocr_validate_charges($input, $invoice_total) {
    if (!is_array($input) || !$input || count($input) > 60) return null;
    $categories = woodcrest_ocr_categories();
    $allocations = woodcrest_ocr_allocations();
    $out = [];
    $sum_cents = 0;
    foreach ($input as $record) {
        if (!is_array($record)) return null;
        $category = strtoupper(sanitize_key((string)($record['category'] ?? 'OTHER')));
        $allocation = strtoupper(sanitize_key((string)($record['allocation'] ?? 'UNASSIGNED')));
         
        if (!isset($categories[$category]) || !isset($allocations[$allocation])) return null;
        $description = sanitize_text_field((string)($record['description'] ?? ''));
        if ($description === '' || strlen($description) > 190) return null;
        $value = trim((string)($record['amount'] ?? ''));
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $value)) return null;
        $cents = (int)round((float)$value * 100);
        $year = (int)($record['expense_year'] ?? 0);
        if ($year < 2000 || $year > 2099) return null;
        $meter = sanitize_text_field((string)($record['meter_number'] ?? ''));
        if (strlen($meter) > 64) return null;
        $sum_cents += $cents;
        $out[] = [
            'category' => $category, 'description' => $description,
            'amount' => number_format($cents / 100, 2, '.', ''),
            'allocation' => $allocation, 'expense_year' => $year, 'meter_number' => $meter,
        ];
    }
    if (abs($sum_cents - (int)round((float)$invoice_total * 100)) > 1) return null;
    return $out;
}

function woodcrest_ocr_review_payload($raw) {
    $fields = woodcrest_ocr_clean_fields($raw);
    if (!$fields || !$fields['provider'] || !$fields['bill_number'] || !$fields['period_start'] || !$fields['period_end'] || $fields['total'] === '') {
        wp_send_json_error(['message' => 'Complete provider, bill number, service dates, and total amount.'], 400);
    }
    $charges = woodcrest_ocr_validate_charges($raw['charge_rows'] ?? null, $fields['total']);
    if ($charges === null) {
        wp_send_json_error(['message' => 'Check charge categories, classifications, years, and amounts. Charges must equal the invoice total (within $0.01).'], 400);
    }
    $fields['charge_rows'] = $charges;
    foreach ($fields['meter_rows'] as $meter) {
        if ($meter['previous'] !== '' && $meter['current'] !== '' && $meter['usage'] !== ''
            && abs(((float)$meter['current'] - (float)$meter['previous']) - (float)$meter['usage']) > 0.01) {
            wp_send_json_error(['message' => 'Meter ' . $meter['number'] . ' usage does not match current minus previous reading.'], 400);
        }
    }
    return [$fields, $charges];
}

function woodcrest_ocr_duplicate($id, $property_id, $fields) {
    global $wpdb;
    $table = woodcrest_ocr_table();
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE supplier_invoice_id <> %d AND property_id = %d AND status='CONFIRMED'
         AND JSON_UNQUOTE(JSON_EXTRACT(invoice_json, '$.bill_number')) = %s
         AND JSON_UNQUOTE(JSON_EXTRACT(invoice_json, '$.provider')) = %s",
        $id, $property_id, $fields['bill_number'], $fields['provider']
    ));
}

 
function woodcrest_ocr_write_review($id, $row, $fields, $charges, $is_update, $post_choices = null) {
    global $wpdb;
    $table = woodcrest_ocr_table();
    $charge_table = woodcrest_ocr_charge_table();
    if (!woodcrest_fe_table_exists($charge_table)) {
        wp_send_json_error(['message' => 'Invoice charge storage is unavailable. Open WordPress Admin to initialize it.'], 500);
    }
    if (woodcrest_ocr_duplicate($id, (int)$row->property_id, $fields)) {
        wp_send_json_error(['message' => 'An invoice with this provider and bill number already exists for the property.'], 409);
    }
    if ($post_choices !== null && !$is_update) {
        $schema = woodcrest_v25_tables();
        if (!woodcrest_fe_table_exists($schema['posting']) || !woodcrest_fe_table_exists($schema['audit'])) {
            wp_send_json_error(['message' => 'Utility posting tables need initialization. Open WordPress Admin before importing.'], 500);
        }
        [$preflight, $preflight_errors] = woodcrest_v25_validate_posting((int)$row->property_id, $fields, $post_choices);
        if ($preflight_errors) wp_send_json_error(['message' => implode(' ', $preflight_errors)], 409);
    }
    $wpdb->query('START TRANSACTION');
    $updated = $wpdb->update($table, [
        'status' => 'CONFIRMED', 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time('mysql'),
        'invoice_json' => wp_json_encode($fields),
    ], ['supplier_invoice_id' => $id, 'status' => $is_update ? 'CONFIRMED' : 'DRAFT'], ['%s','%d','%s','%s'], ['%d','%s']);
    $ok = ($updated === 1 || ($is_update && $updated === 0));
    if ($ok && $is_update) $ok = $wpdb->delete($charge_table, ['supplier_invoice_id' => $id], ['%d']) !== false;
    if ($ok) {
        foreach ($charges as $i => $charge) {
            $ok = (bool)$wpdb->insert($charge_table, [
                'supplier_invoice_id' => $id, 'line_number' => $i + 1,
                'charge_category' => $charge['category'], 'description' => $charge['description'],
                'amount' => $charge['amount'], 'allocation' => $charge['allocation'],
                'expense_year' => $charge['expense_year'], 'meter_number' => $charge['meter_number'],
            ], ['%d','%d','%s','%s','%s','%s','%d','%s']);
            if (!$ok) break;
        }
    }
    $posted = null;
    if ($ok && $post_choices !== null && !$is_update) {
        $posted = woodcrest_v25_post_invoice($id, (int)$row->property_id, $fields, $post_choices);
        if (is_wp_error($posted)) $ok = false;
    }
    if (!$ok) {
        $wpdb->query('ROLLBACK');
        $reason = is_wp_error($posted) ? $posted->get_error_message() : 'Unable to save invoice changes; existing records were preserved.';
        wp_send_json_error(['message' => $reason], 409);
    }
    $wpdb->query('COMMIT');
    return $posted;
}

function woodcrest_ocr_ajax_confirm() {
    woodcrest_ocr_authorized_ajax();
    $id = absint($_POST['draft_id'] ?? 0);
    $row = woodcrest_ocr_draft($id);
    if (!$row || $row->status !== 'DRAFT' || !woodcrest_ocr_has_property_access((int)$row->property_id)) {
        wp_send_json_error(['message' => 'Pending invoice not found or access denied.'], 403);
    }
    if (!current_user_can('manage_options') && (int)$row->created_by !== get_current_user_id()) {
        wp_send_json_error(['message' => 'You may only confirm your own pending imports.'], 403);
    }
    $raw = json_decode(wp_unslash((string)($_POST['fields'] ?? '')), true);
    [$fields, $charges] = woodcrest_ocr_review_payload($raw);
    $post_choices = json_decode(wp_unslash((string)($_POST['post_choices'] ?? '{}')), true);
    if (!is_array($post_choices)) wp_send_json_error(['message' => 'Invalid utility posting selections.'], 400);
    $posted = woodcrest_ocr_write_review($id, $row, $fields, $charges, false, $post_choices);
    $counts = $posted['counts'] ?? ['readings'=>0,'costs'=>0];
    wp_send_json_success(['id' => $id, 'posted' => $counts, 'message' => 'Invoice confirmed. Posted ' . (int)$counts['readings'] . ' readings and ' . (int)$counts['costs'] . ' utility costs. No tenant invoices were generated.']);
}
add_action('wp_ajax_woodcrest_ocr_confirm', 'woodcrest_ocr_ajax_confirm');

function woodcrest_ocr_ajax_load_invoice() {
    woodcrest_ocr_authorized_ajax();
    $id = absint($_POST['invoice_id'] ?? 0);
    global $wpdb;
    $table = woodcrest_ocr_table();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT supplier_invoice_id, property_id, status, original_filename, invoice_json,
                CASE WHEN LENGTH(source_pdf)>0 THEN 1 ELSE 0 END AS has_pdf
         FROM {$table} WHERE supplier_invoice_id=%d", $id));
    if (!$row || $row->status !== 'CONFIRMED' || !woodcrest_ocr_has_property_access((int)$row->property_id)) {
        wp_send_json_error(['message' => 'Invoice not found or access denied.'], 403);
    }
    $fields = json_decode($row->invoice_json, true);
    if (!is_array($fields)) wp_send_json_error(['message' => 'This invoice cannot be opened for editing.'], 500);
    $pdf_url = (int)$row->has_pdf ? add_query_arg([
        'action' => 'woodcrest_ocr_view_pdf', 'id' => $id,
        'nonce' => wp_create_nonce('woodcrest_ocr_view_' . $id),
    ], admin_url('admin-ajax.php')) : '';
    wp_send_json_success([
        'id' => $id, 'property_id' => (int)$row->property_id, 'fields' => $fields,
        'pdf_url' => $pdf_url, 'filename' => $row->original_filename,
        'revision' => hash('sha256', $row->invoice_json),
    ]);
}
add_action('wp_ajax_woodcrest_ocr_load_invoice', 'woodcrest_ocr_ajax_load_invoice');

function woodcrest_ocr_ajax_update_invoice() {
    woodcrest_ocr_authorized_ajax();
    $id = absint($_POST['invoice_id'] ?? 0);
    $row = woodcrest_ocr_draft($id);
    if (!$row || $row->status !== 'CONFIRMED' || !woodcrest_ocr_has_property_access((int)$row->property_id)) {
        wp_send_json_error(['message' => 'Invoice not found or access denied.'], 403);
    }
    $revision = sanitize_text_field(wp_unslash((string)($_POST['revision'] ?? '')));
    if (!hash_equals(hash('sha256', $row->invoice_json), $revision)) {
        wp_send_json_error(['message' => 'Someone changed this invoice since you opened it. Refresh and review the latest version before saving.'], 409);
    }
    $raw = json_decode(wp_unslash((string)($_POST['fields'] ?? '')), true);
    [$fields, $charges] = woodcrest_ocr_review_payload($raw);
    woodcrest_ocr_write_review($id, $row, $fields, $charges, true);
    wp_send_json_success(['id' => $id, 'message' => 'Invoice updated. The expense reference now uses the revised charges. Triple Net is unchanged.']);
}
add_action('wp_ajax_woodcrest_ocr_update_invoice', 'woodcrest_ocr_ajax_update_invoice');

function woodcrest_ocr_ajax_view() {
    $id = absint($_GET['id'] ?? 0);
    if (!is_user_logged_in() || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'] ?? '')), 'woodcrest_ocr_view_' . $id)) wp_die('Access denied.', '', ['response' => 403]);
    $row = woodcrest_ocr_draft($id);
    if (!$row || !woodcrest_ocr_has_property_access((int)$row->property_id)) wp_die('Access denied.', '', ['response' => 403]);
    if (!$row->source_pdf) wp_die('No PDF is attached to this manual entry.', '', ['response' => 404]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="invoice.pdf"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $row->source_pdf;
    exit;
}
add_action('wp_ajax_woodcrest_ocr_view_pdf', 'woodcrest_ocr_ajax_view');

 
function woodcrest_ocr_annual_reference_html($property_id, $expense_year) {
    global $wpdb;
    $property_id = (int)$property_id;
    $expense_year = (int)$expense_year;
    if (!$property_id || !woodcrest_ocr_has_property_access($property_id)) return '';
    if (!woodcrest_fe_table_exists(woodcrest_ocr_charge_table()) || !woodcrest_fe_table_exists(woodcrest_ocr_table())) return '<div class="wc-tn-card wc-ocr-reference">Invoice reference tables have not been initialized. Open WordPress Admin once, then reload.</div>';
    $c = woodcrest_ocr_charge_table();
    $i = woodcrest_ocr_table();
    $summary = $wpdb->get_results($wpdb->prepare(
        "SELECT c.allocation, c.charge_category, COUNT(*) AS line_count, SUM(c.amount) AS total
         FROM {$c} c INNER JOIN {$i} i ON i.supplier_invoice_id = c.supplier_invoice_id
         WHERE i.property_id = %d AND i.status = 'CONFIRMED' AND c.expense_year = %d
         GROUP BY c.allocation, c.charge_category ORDER BY c.allocation, c.charge_category",
        $property_id, $expense_year
    ));
    $list = $wpdb->get_results($wpdb->prepare(
        "SELECT c.description, c.charge_category, c.allocation, c.amount, c.meter_number,
                i.supplier_invoice_id, i.original_filename, i.invoice_json
         FROM {$c} c INNER JOIN {$i} i ON i.supplier_invoice_id = c.supplier_invoice_id
         WHERE i.property_id = %d AND i.status = 'CONFIRMED' AND c.expense_year = %d
         ORDER BY i.supplier_invoice_id DESC, c.line_number LIMIT 50",
        $property_id, $expense_year
    ));
    $categories = woodcrest_ocr_categories();
    $allocations = woodcrest_ocr_allocations();
    $display_allocations = array_map(static function ($label) { return str_ireplace(' reference', '', $label); }, $allocations);
    $totals = array_fill_keys(array_keys($allocations), 0.0);
    foreach ($summary as $item) $totals[$item->allocation] = ($totals[$item->allocation] ?? 0) + (float)$item->total;
    $unclassified = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$i} i LEFT JOIN {$c} c ON i.supplier_invoice_id = c.supplier_invoice_id
         WHERE i.property_id=%d AND i.status='CONFIRMED' AND c.supplier_invoice_id IS NULL", $property_id
    ));
    $years = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT c.expense_year FROM {$c} c INNER JOIN {$i} i ON i.supplier_invoice_id=c.supplier_invoice_id
         WHERE i.property_id=%d AND i.status='CONFIRMED' ORDER BY c.expense_year DESC", $property_id
    ));
    ob_start(); ?>
    <div class="wc-role-manager-content wc-ocr-reference-scope"><section class="wc-tn-card wc-tn-card-reference wc-ocr-reference">
      <div class="wc-tn-card-heading"><h2>Invoice Expenses — <?php echo (int)$expense_year; ?></h2>
        <p>Amounts from saved invoices to compare with annual Triple Net costs.</p></div>
      <?php if ($unclassified): ?><p class="wc-ocr-unclassified-notice"><?php echo (int)$unclassified; ?> saved invoice(s) have no charge details yet. Open them under Imported Bills and save their individual charges to include them here.</p><?php endif; ?>
      <?php if (!$summary): ?>
        <div class="wc-tn-empty">No confirmed invoice charges for this property and year.
          <?php if ($years): ?><p>Years with recorded invoices: <?php echo esc_html(implode(', ', array_map('intval', $years))); ?>. Choose one of those years above.</p><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="wc-ocr-reference-totals">
        <?php foreach ($allocations as $key => $label): ?>
          <div><small><?php echo esc_html($display_allocations[$key]); ?></small><strong>$<?php echo esc_html(number_format($totals[$key] ?? 0,2)); ?></strong></div>
        <?php endforeach; ?>
        </div>
        <details class="wc-ocr-reference-detail"><summary>Category breakdown</summary>
          <div class="wc-tn-table-wrap"><table class="wc-tn-table"><thead><tr><th>Classification</th><th>Category</th><th>Lines</th><th>Total</th></tr></thead><tbody>
          <?php foreach ($summary as $item): ?>
            <tr><td><?php echo esc_html($display_allocations[$item->allocation] ?? $item->allocation); ?></td>
                <td><?php echo esc_html($categories[$item->charge_category] ?? $item->charge_category); ?></td>
                <td><?php echo (int)$item->line_count; ?></td><td>$<?php echo esc_html(number_format((float)$item->total,2)); ?></td></tr>
          <?php endforeach; ?>
          </tbody></table></div></details>
        <details class="wc-ocr-reference-detail"><summary>Invoice charge details (latest 50)</summary>
          <div class="wc-tn-table-wrap"><table class="wc-tn-table"><thead><tr><th>Provider / Bill</th><th>Charge</th><th>Classification</th><th>Amount</th><th>PDF</th></tr></thead><tbody>
            <?php foreach ($list as $item):
              $invoice = json_decode($item->invoice_json, true) ?: [];
              $id = (int)$item->supplier_invoice_id;
              $url = add_query_arg(['action'=>'woodcrest_ocr_view_pdf','id'=>$id,'nonce'=>wp_create_nonce('woodcrest_ocr_view_' . $id)], admin_url('admin-ajax.php'));
            ?>
              <tr><td><?php echo esc_html(($invoice['provider'] ?? '') . ' #' . ($invoice['bill_number'] ?? $id)); ?></td>
                  <td><?php echo esc_html($item->description); ?></td>
                  <td><?php echo esc_html($display_allocations[$item->allocation] ?? $item->allocation); ?></td>
                  <td>$<?php echo esc_html(number_format((float)$item->amount,2)); ?></td>
                  <td><?php if ($item->original_filename === 'Manual entry'): ?>Manual<?php else: ?><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">View PDF</a><?php endif; ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div></details>
      <?php endif; ?>
    </section></div>
    <?php return ob_get_clean();
}

 
function woodcrest_ocr_reference_shortcode($atts = []) {
    if (!current_user_can('manage_options') && ($gate = woodcrest_fe_access_message('manager'))) return $gate;
    $ids = woodcrest_ocr_allowed_property_ids();
    if (!$ids) return '<div class="wc-tn-card">No properties assigned.</div>';
    global $wpdb;
    $t = woodcrest_fe_tables();
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $properties = $wpdb->get_results($wpdb->prepare("SELECT property_id,property_name FROM {$t['property']} WHERE property_id IN ($placeholders) ORDER BY property_name", $ids));
    $default_property = $ids[0];
    $default_year = (int)current_time('Y') - 1;
    $has_reference = woodcrest_fe_table_exists(woodcrest_ocr_charge_table()) && woodcrest_fe_table_exists(woodcrest_ocr_table());
    if ($has_reference) {
        $c = woodcrest_ocr_charge_table(); $i = woodcrest_ocr_table();
         
        $latest = $wpdb->get_row($wpdb->prepare(
            "SELECT i.property_id, c.expense_year FROM {$i} i INNER JOIN {$c} c ON i.supplier_invoice_id=c.supplier_invoice_id
             WHERE i.status='CONFIRMED' AND i.property_id IN ($placeholders)
             ORDER BY i.supplier_invoice_id DESC, c.line_number ASC LIMIT 1", ...$ids));
        if ($latest) { $default_property = (int)$latest->property_id; $default_year = (int)$latest->expense_year; }
    }
    $chosen = absint($_GET['wc_ref_property'] ?? $_GET['wc_tn_property'] ?? $default_property);
    if (!in_array($chosen, $ids, true)) $chosen = $default_property;
    $selected_year = isset($_GET['wc_ref_year']) || isset($_GET['wc_tn_expense_year']);
    if (!$selected_year && $has_reference) {
        $c = woodcrest_ocr_charge_table(); $i = woodcrest_ocr_table();
        $latest_year = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT c.expense_year FROM {$i} i INNER JOIN {$c} c ON i.supplier_invoice_id=c.supplier_invoice_id
             WHERE i.property_id=%d AND i.status='CONFIRMED' ORDER BY i.supplier_invoice_id DESC, c.line_number ASC LIMIT 1", $chosen));
        if ($latest_year) $default_year = $latest_year;
    }
    $year = (int)($_GET['wc_ref_year'] ?? $_GET['wc_tn_expense_year'] ?? $default_year);
    $year = max(2000,min(2099,$year));
    $years = [];
    if ($has_reference) $years = array_map('intval',(array)$wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT c.expense_year FROM {$c} c INNER JOIN {$i} i ON i.supplier_invoice_id=c.supplier_invoice_id
         WHERE i.property_id=%d AND i.status='CONFIRMED' ORDER BY c.expense_year DESC",$chosen)));
    ob_start(); ?>
    <section class="wc-tn-card wc-ocr-reference-selector wc-role-manager-content">
      <div class="wc-tn-card-heading"><h2>Annual Invoice Expenses</h2><p>Review invoice charges by property and year.</p></div>
      <form method="get" class="wc-tn-form wc-tn-selector-form">
        <div class="wc-tn-field"><label for="wc_ref_property">Property</label><select name="wc_ref_property" id="wc_ref_property">
          <?php foreach ($properties as $property): ?><option value="<?php echo (int)$property->property_id; ?>" <?php selected($chosen,$property->property_id); ?>><?php echo esc_html($property->property_name); ?></option><?php endforeach; ?>
        </select></div>
        <div class="wc-tn-field"><label for="wc_ref_year">Expense year</label><input id="wc_ref_year" type="number" min="2000" max="2099" name="wc_ref_year" value="<?php echo (int)$year; ?>"></div>
        <div class="wc-tn-field wc-tn-load-field"><button class="wc-tn-button" type="submit">View expenses</button></div>
      </form>
      <?php if ($years): ?><p class="wc-ocr-available-years">Years with invoices for this property: <?php echo esc_html(implode(', ', $years)); ?></p><?php endif; ?>
    </section>
    <?php return '<div class="wc-tn-page wc-role-manager-content">' . ob_get_clean() . woodcrest_ocr_annual_reference_html($chosen,$year) . '</div>';
}
add_shortcode('woodcrest_supplier_expense_reference','woodcrest_ocr_reference_shortcode');
add_shortcode('woodcrest_expense_reference','woodcrest_ocr_reference_shortcode');

function woodcrest_pm_import_invoice_shortcode() {
    if (!current_user_can('manage_options') && ($gate = woodcrest_fe_access_message('manager'))) return $gate;
    global $wpdb;
    $ids = woodcrest_ocr_allowed_property_ids();
    if (!$ids) return woodcrest_fe_manager_module_wrap('<div class="woodcrest-card">No assigned properties.</div>');
    if (!woodcrest_fe_table_exists(woodcrest_ocr_table())) return woodcrest_fe_manager_module_wrap('<div class="woodcrest-card">Invoice storage needs initialization. Open WordPress Admin once, then refresh this page.</div>');
    $table = woodcrest_fe_tables();
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $properties = $wpdb->get_results($wpdb->prepare("SELECT property_id, property_name FROM {$table['property']} WHERE property_id IN ({$placeholders}) ORDER BY property_name", $ids));
    $preferred_property = absint($_GET['wc_ocr_property'] ?? 0);
    if (!in_array($preferred_property, $ids, true)) $preferred_property = 0;
    ob_start(); ?>
    <section class="woodcrest-card woodcrest-card-wide wc-ocr-import" data-wc-ocr="1">
      <div class="woodcrest-card-header wc-ocr-heading"><h2>Invoice Import</h2><p>Upload a PDF or enter an invoice manually.</p></div>
      <div class="wc-ocr-start">
        <form class="wc-ocr-upload-form" enctype="multipart/form-data">
          <div class="woodcrest-fe-field"><label for="wc-ocr-property">Property</label><select name="property_id" id="wc-ocr-property" required><option value="">Select property</option>
            <?php foreach ($properties as $property): ?><option value="<?php echo (int)$property->property_id; ?>" <?php selected($preferred_property, (int)$property->property_id); ?>><?php echo esc_html($property->property_name); ?></option><?php endforeach; ?>
          </select></div>
          <div class="woodcrest-fe-field"><label for="wc-ocr-pdf">Invoice PDF</label><input id="wc-ocr-pdf" name="supplier_pdf" type="file" accept="application/pdf,.pdf" required></div>
          <div class="wc-ocr-upload-actions"><button class="woodcrest-fe-button" type="submit">Extract PDF</button><button class="wc-ocr-manual" type="button">Enter manually</button></div>
        </form>
      </div>
      <?php if (isset($_GET['wc_import_saved']) && in_array(sanitize_key(wp_unslash((string)$_GET['wc_import_saved'])), ['new','updated'], true)): ?>
      <div class="wc-ocr-flash">Invoice <?php echo $_GET['wc_import_saved'] === 'updated' ? 'updated' : 'saved'; ?>. <?php if (isset($_GET['wc_post_readings'])): ?><?php echo (int)absint($_GET['wc_post_readings']); ?> readings and <?php echo (int)absint($_GET['wc_post_costs'] ?? 0); ?> utility cost records posted to Manage Utilities.<?php else: ?>Expense records updated.<?php endif; ?></div>
      <?php endif; ?>
      <div class="wc-ocr-status" role="status" aria-live="polite" hidden></div>
      <div class="wc-ocr-review" hidden>
        <div class="wc-ocr-review-intro"><span class="wc-ocr-step">Step 2 of 2</span><h3 class="wc-ocr-review-title">Review invoice</h3><p>Review details and charges before saving.</p></div>
        <div class="wc-ocr-columns">
          <div class="wc-ocr-preview-panel">
            <details class="wc-ocr-pdf-details"><summary>View PDF <span></span></summary>
              <div class="wc-ocr-pdf-toolbar" role="toolbar" aria-label="PDF viewing controls">
                <button type="button" class="wc-ocr-pdf-prev" aria-label="Previous PDF page">&#8592;</button>
                <span class="wc-ocr-pdf-page">Page 1 / 1</span>
                <button type="button" class="wc-ocr-pdf-next" aria-label="Next PDF page">&#8594;</button>
                <span class="wc-ocr-pdf-divider"></span>
                <button type="button" class="wc-ocr-pdf-zoom-out" aria-label="Zoom out">&#8722;</button>
                <span class="wc-ocr-pdf-zoom-label">100%</span>
                <button type="button" class="wc-ocr-pdf-zoom-in" aria-label="Zoom in">+</button>
                <button type="button" class="wc-ocr-pdf-fit">Fit width</button>
                <a class="wc-ocr-pdf-open" target="_blank" rel="noopener" href="#" hidden>Open full PDF &#8599;</a>
              </div>
              <div class="wc-ocr-pdf-viewport"><div class="wc-ocr-pdf-canvas-holder"><canvas class="wc-ocr-pdf-canvas" role="img" aria-label="PDF page preview"></canvas></div></div>
              <p class="wc-ocr-pdf-error" role="status" hidden></p>
            </details>
          </div>
          <div class="wc-ocr-review-panel"><form class="wc-ocr-review-form">
            <section class="wc-ocr-section wc-ocr-details-section">
              <div class="wc-ocr-section-head"><h4>Bill details</h4><p></p></div>
              <input type="hidden" name="water_rate" value=""><input type="hidden" name="electric_rate" value="">
              <div class="wc-ocr-grid">
              <?php
              $fields = [
                'provider'=>'Provider','bill_number'=>'Bill number','account_number'=>'Account number','service_address'=>'Service address',
                'bill_date'=>'Bill date','due_date'=>'Due date','period_start'=>'Period start','period_end'=>'Period end',
                'total'=>'Invoice total ($)'
              ];
              foreach ($fields as $field => $label):
                $type = in_array($field, ['bill_date','due_date','period_start','period_end'], true) ? 'date' : ($field === 'total' ? 'number' : 'text');
                ?>
                <div class="woodcrest-fe-field wc-ocr-field-<?php echo esc_attr($field); ?>"><label for="wc-ocr-<?php echo esc_attr($field); ?>"><?php echo esc_html($label); ?></label><input id="wc-ocr-<?php echo esc_attr($field); ?>" name="<?php echo esc_attr($field); ?>" type="<?php echo $type; ?>" <?php echo $type === 'number' ? 'step="0.01" min="0"' : ''; ?>></div>
              <?php endforeach; ?>
              </div>
            </section>
            <section class="wc-ocr-section wc-ocr-charges-section">
              <div class="wc-ocr-section-head"><h4>Invoice charges <span class="wc-ocr-charge-count" aria-live="polite"></span></h4></div>
              <div class="wc-ocr-charges"></div>
              <button type="button" class="wc-ocr-add-charge">+ Add another charge</button>
              <div class="wc-ocr-reconcile" aria-live="polite"></div>
            </section>
            <details class="wc-ocr-optional wc-ocr-meter-section">
              <summary>Meter readings <span class="wc-ocr-meter-count">Optional</span></summary>
              <div class="wc-ocr-meter-body">
                <div class="wc-ocr-meters"></div><button type="button" class="wc-ocr-add-meter">+ Add meter</button></div>
            </details>
            <section class="wc-ocr-section wc-v25-posting-section">
              <div class="wc-ocr-section-head"><h4>Update Manage Utilities</h4></div>
              <div class="wc-v25-posting-status" role="status" aria-live="polite">Choose a property and enter invoice details to match existing meters.</div>
              <div class="wc-v25-reading-matches"></div>
              <div class="wc-v25-cost-matches"></div>
              <button type="button" class="wc-v25-refresh-matches">Refresh matches</button>
            </section>
            <details class="wc-ocr-optional wc-ocr-notes-section"><summary>Review notes <span>Optional</span></summary>
              <div class="wc-ocr-notes-body woodcrest-fe-field"><label for="wc-ocr-notes">Notes about this invoice</label><textarea id="wc-ocr-notes" name="notes" rows="3" placeholder="Any details you want to keep with this invoice"></textarea></div>
            </details>
            <div class="wc-ocr-save-bar"><p></p><div class="wc-ocr-save-actions"><button type="button" class="wc-ocr-cancel-edit" hidden>Cancel editing</button><button class="woodcrest-fe-button wc-ocr-save-button" type="submit">Save Invoice</button></div></div>
          </form></div>
        </div>
      </div>
    </section>
    <?php
    $html = ob_get_clean();
    $import_table = woodcrest_ocr_table();
     
    $bill_count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$import_table} WHERE status='CONFIRMED' AND property_id IN ({$placeholders})", $ids
    ));
    $per_page = 20;
    $total_pages = max(1, (int)ceil($bill_count / $per_page));
    $current_page = min($total_pages, max(1, absint($_GET['wc_invoice_page'] ?? 1)));
    $offset = ($current_page - 1) * $per_page;
    $bills = $wpdb->get_results($wpdb->prepare(
        "SELECT supplier_invoice_id, property_id, original_filename, invoice_json, reviewed_at
         FROM {$import_table} WHERE status = 'CONFIRMED' AND property_id IN ({$placeholders})
         ORDER BY supplier_invoice_id DESC LIMIT %d OFFSET %d", ...array_merge($ids, [$per_page, $offset])
    ));
    if ($bills) {
        $names = [];
        foreach ($properties as $property) $names[(int)$property->property_id] = $property->property_name;
        $html .= '<section class="woodcrest-card woodcrest-card-wide wc-ocr-history"><div class="woodcrest-card-header"><h2>Imported Bills</h2></div>';
        $rows = [];
        foreach ($bills as $bill) {
            $details = json_decode($bill->invoice_json, true) ?: [];
            $bill_id = (int) $bill->supplier_invoice_id;
            $url = add_query_arg([
                'action' => 'woodcrest_ocr_view_pdf',
                'id' => $bill_id,
                'nonce' => wp_create_nonce('woodcrest_ocr_view_' . $bill_id),
            ], admin_url('admin-ajax.php'));
            $rows[] = [
                esc_html((string)($names[(int)$bill->property_id] ?? 'Property')),
                esc_html((string)($details['provider'] ?? '—')),
                esc_html((string)($details['bill_number'] ?? '—')),
                woodcrest_fe_period_date_cell((string)($details['period_start'] ?? '')),
                woodcrest_fe_period_date_cell((string)($details['period_end'] ?? '')),
                esc_html((string)($details['total'] ?? '—')),
                ($bill->original_filename === 'Manual entry' ? 'Manual entry' : '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">PDF</a>'),
                '<button type="button" class="wc-ocr-history-edit" data-wc-invoice-id="' . $bill_id . '">View / Edit</button>',
            ];
        }
        $html .= woodcrest_fe_property_grouped_table(['Property','Provider','Bill #','Period Start','Period End','Total ($)','PDF','Actions'], $rows, 0, ['date_column'=>4]);
        if ($total_pages > 1) {
            $html .= '<nav class="wc-ocr-history-pages" aria-label="Invoice history pages">';
            if ($current_page > 1) $html .= '<a href="' . esc_url(add_query_arg('wc_invoice_page', $current_page - 1)) . '">&larr; Previous</a>';
            $html .= '<span>Page ' . $current_page . ' of ' . $total_pages . '</span>';
            if ($current_page < $total_pages) $html .= '<a href="' . esc_url(add_query_arg('wc_invoice_page', $current_page + 1)) . '">Next &rarr;</a>';
            $html .= '</nav>';
        }
        $html .= '</section>';
    } else {
        $html .= '<section class="woodcrest-card woodcrest-card-wide wc-ocr-history"><div class="woodcrest-card-header"><h2>Imported Bills</h2><p>No invoices yet.</p></div></section>';
    }
    return woodcrest_fe_manager_module_wrap($html);
}
add_shortcode('woodcrest_pm_import_invoice', 'woodcrest_pm_import_invoice_shortcode');
add_shortcode('woodcrest_manager_import_invoice', 'woodcrest_pm_import_invoice_shortcode');
