<?php
if (!defined('ABSPATH')) exit;

add_shortcode('wc_pm_triple_net', 'wc_tn_shortcode_full');
add_shortcode('wc_pm_triple_net_manage', 'wc_tn_shortcode_manage');
add_shortcode('wc_pm_triple_net_allocations', 'wc_tn_shortcode_allocations');
add_shortcode('wc_pm_triple_net_summary', 'wc_tn_shortcode_summary');
add_shortcode('wc_pm_triple_net_selector', 'wc_tn_shortcode_selector');
add_shortcode('wc_pm_triple_net_setup', 'wc_tn_shortcode_setup');
add_shortcode('wc_pm_triple_net_charges', 'wc_tn_shortcode_charges');
add_shortcode('wc_pm_triple_net_history', 'wc_tn_shortcode_history');

function wc_tn_access_guard() {
    if (!is_user_logged_in()) return '<div class="wc-tn-notice error">Please sign in to manage Triple Net data.</div>';
    $issues = wc_tn_schema_issues();
    if ($issues) return '<div class="wc-tn-notice error">WC Triple Net database update is required. Missing: ' . esc_html(implode(', ', $issues)) . '</div>';
    if (!wc_tn_manager_property_ids()) return '<div class="wc-tn-notice error">No properties are assigned to this account.</div>';
    return '';
}

function wc_tn_notice_html() {
    static $shown = false;
    if ($shown || empty($_GET['wc_tn_message'])) return '';
    $shown = true;
    $status = ($_GET['wc_tn_status'] ?? '') === 'success' ? 'success' : 'error';
    return '<div class="wc-tn-notice ' . esc_attr($status) . '" data-wc-flash="' . esc_attr($status) . '">' . esc_html(wp_unslash($_GET['wc_tn_message'])) . '</div>';
}

function wc_tn_selector_html($ctx) {
    $action = get_permalink() ?: home_url('/');
    ob_start(); ?>
    <section class="wc-tn-card wc-tn-card-selector wc-tn-selector-card">
        <div class="wc-tn-card-heading"><h2>Triple Net</h2><p>Choose a property and expense year.</p></div>
        <form method="get" action="<?php echo esc_url($action); ?>" class="wc-tn-form wc-tn-selector-form wc-tn-track-form">
            <?php if (current_user_can('manage_options') && woodcrest_fe_effective_manager_id() !== get_current_user_id()): ?>
                <input type="hidden" name="manager_id" value="<?php echo (int) woodcrest_fe_effective_manager_id(); ?>">
            <?php endif; ?>
            <div class="wc-tn-field">
                <label for="wc_tn_property">Property</label>
                <select id="wc_tn_property" name="wc_tn_property" required>
                    <?php foreach ($ctx['properties'] as $property): ?>
                        <option value="<?php echo (int) $property->property_id; ?>" <?php selected($ctx['property_id'], $property->property_id); ?>><?php echo esc_html($property->property_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wc-tn-field"><label for="wc_tn_expense_year">Expense Year</label><input id="wc_tn_expense_year" name="wc_tn_expense_year" type="number" min="2000" max="2099" value="<?php echo (int) $ctx['expense_year']; ?>" required></div>
            <div class="wc-tn-field"><label for="wc_tn_billing_year">Billing Year</label><input id="wc_tn_billing_year" class="wc-tn-readonly" name="wc_tn_billing_year" type="number" value="<?php echo (int) $ctx['billing_year']; ?>" readonly aria-readonly="true"></div>
            <div class="wc-tn-field wc-tn-load-field"><button class="wc-tn-button wc-tn-track-dirty" type="submit" data-idle-label="Load Selection" data-ready-label="Load Selection">Load Selection</button></div>
        </form>
    </section>
    <?php return ob_get_clean();
}

function wc_tn_manage_html($ctx) {
    $property = wc_tn_property($ctx['property_id']);
    if (!$property) return '';

    $suite_total = wc_tn_property_suite_sqft($ctx['property_id']);
    $occupancies = wc_tn_eligible_occupancies($ctx['property_id'], $ctx['billing_year']);
    $occupied_sqft = wc_tn_unique_occupied_sqft($occupancies);
    $vacant_sqft = max(0, $suite_total - $occupied_sqft);

    $rec = wc_tn_get_reconciliation($ctx['property_id'], $ctx['expense_year'], $ctx['billing_year']);
    $expenses = $rec ? wc_tn_get_expenses((int) $rec->nnn_reconciliation_id) : [];
    $allocable = $rec ? (float) $rec->total_allocable_sqft : $suite_total;
    $drafted = $rec && wc_tn_status_is_drafted($rec->status);
    $woodcrest_fixed_percent = $rec ? wc_tn_woodcrest_fixed_percent($rec) : 0.0;
    $woodcrest_fixed_enabled = $woodcrest_fixed_percent > 0;

    $types = [
        'CAM' => ['label' => 'CAM', 'input' => 'cam_total', 'notes' => 'cam_notes'],
        'INSURANCE' => ['label' => 'Insurance', 'input' => 'insurance_total', 'notes' => 'insurance_notes'],
        'PROPERTY_TAX' => ['label' => 'Property Tax', 'input' => 'property_tax_total', 'notes' => 'property_tax_notes'],
    ];

    ob_start(); ?>
    <section class="wc-tn-card wc-tn-card-setup" data-wc-tn-allocable-sqft="<?php echo esc_attr(number_format($suite_total, 2, '.', '')); ?>" data-wc-tn-occupied-sqft="<?php echo esc_attr(number_format($occupied_sqft, 2, '.', '')); ?>">
        <div class="wc-tn-card-heading wc-tn-heading-split">
            <div><h2>Triple Net Costs</h2><p>Enter annual costs and review the property square footage.</p></div>
            <span class="wc-tn-status <?php echo $drafted ? 'drafted' : 'undrafted'; ?>"><?php echo esc_html(wc_tn_status_label($rec ? $rec->status : 'UNDRAFTED')); ?></span>
        </div>

        <?php if ($suite_total <= 0): ?>
            <div class="wc-tn-notice error">No suite square footage is available for this property.</div>
        <?php endif; ?>
        <?php if (!$rec): ?>
            <div class="wc-tn-info">No record yet. Save to create an UNDRAFTED record.</div>
        <?php elseif ($drafted): ?>
            <div class="wc-tn-info drafted">DRAFTED for invoices. Return to UNDRAFTED to make changes.</div>
        <?php endif; ?>

        <div class="wc-tn-metric-grid wc-tn-metric-grid-four">
            <div class="wc-tn-metric"><span>Allocable Sq. Ft.</span><strong><?php echo esc_html(number_format($suite_total, 2)); ?></strong></div>
            <div class="wc-tn-metric"><span>Occupied Sq. Ft.</span><strong><?php echo esc_html(number_format($occupied_sqft, 2)); ?></strong></div>
            <div class="wc-tn-metric"><span>Vacant Sq. Ft.</span><strong><?php echo esc_html(number_format($vacant_sqft, 2)); ?></strong></div>
            <div class="wc-tn-metric"><span>Billing Year</span><strong><?php echo (int) $ctx['billing_year']; ?></strong></div>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wc-tn-form wc-tn-manage-form wc-tn-track-form"><?php echo wc_tn_manager_scope_hidden_input(); ?>
            <input type="hidden" name="action" value="wc_tn_save_draft">
            <input type="hidden" name="property_id" value="<?php echo (int) $ctx['property_id']; ?>">
            <input type="hidden" name="expense_year" value="<?php echo (int) $ctx['expense_year']; ?>">
            <input type="hidden" name="billing_year" value="<?php echo (int) $ctx['billing_year']; ?>">
            <?php wp_nonce_field('wc_tn_save_draft'); ?>

            <div class="wc-tn-owner-setting">
                <label class="wc-tn-checkbox-label" for="wc_tn_woodcrest_fixed_enabled">
                    <input id="wc_tn_woodcrest_fixed_enabled" class="wc-tn-owner-toggle" name="woodcrest_fixed_enabled" type="checkbox" value="1" <?php checked($woodcrest_fixed_enabled); ?> <?php disabled($drafted); ?>>
                    <span>WoodCrest covers a fixed percent</span>
                </label>
                <div class="wc-tn-percent-input wc-tn-owner-percent-wrap" <?php echo $woodcrest_fixed_enabled ? '' : 'hidden'; ?>>
                    <input id="wc_tn_woodcrest_percent" class="wc-tn-owner-percent" name="woodcrest_responsibility_percent" type="number" min="0.01" max="100" step="0.01" value="<?php echo esc_attr(number_format($woodcrest_fixed_percent, 2, '.', '')); ?>" aria-label="WoodCrest fixed percentage" <?php disabled($drafted || !$woodcrest_fixed_enabled); ?>>
                    <span>%</span>
                </div>
            </div>

            <?php foreach ($types as $type => $meta):
                $expense = $expenses[$type] ?? null;
                $total = $expense ? (float) $expense->total_cost : 0;
                $rate_base = $rec ? (float) $rec->total_allocable_sqft : $suite_total;
                $rate = $rate_base > 0 ? $total / $rate_base : 0;
                $note = $expense ? (string) $expense->notes : '';
            ?>
                <div class="wc-tn-expense-block" data-wc-tn-expense="<?php echo esc_attr($type); ?>">
                    <div class="wc-tn-field"><label for="wc_tn_<?php echo esc_attr($meta['input']); ?>"><?php echo esc_html($meta['label']); ?> Annual Cost</label><input id="wc_tn_<?php echo esc_attr($meta['input']); ?>" class="wc-tn-expense-total" name="<?php echo esc_attr($meta['input']); ?>" type="number" min="0" step="0.01" value="<?php echo esc_attr(number_format($total, 2, '.', '')); ?>" <?php disabled($drafted); ?> required></div>
                    <div class="wc-tn-metric"><span>Rate / Sq. Ft.</span><strong class="wc-tn-live-rate"><?php echo esc_html(wc_tn_money($rate)); ?></strong></div>
                    <div class="wc-tn-field wc-tn-field-wide"><label for="wc_tn_<?php echo esc_attr($meta['notes']); ?>">Notes</label><textarea id="wc_tn_<?php echo esc_attr($meta['notes']); ?>" name="<?php echo esc_attr($meta['notes']); ?>" rows="2" <?php disabled($drafted); ?>><?php echo esc_textarea($note); ?></textarea></div>
                </div>
            <?php endforeach; ?>

            <?php if (!$drafted): ?><div class="wc-tn-actions"><button class="wc-tn-button wc-tn-track-dirty" type="submit" data-idle-label="Save &amp; Recalculate" data-ready-label="Save Changes &amp; Recalculate" <?php disabled($suite_total <= 0); ?>>Save &amp; Recalculate</button></div><?php endif; ?>
        </form>

        <?php if ($rec && !$drafted) echo woodcrest_v24_inline_csv('nnn_expenses'); ?>

        <?php if ($rec && !$drafted): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wc-tn-inline-form wc-tn-finalize-form"><?php echo wc_tn_manager_scope_hidden_input(); ?>
                <input type="hidden" name="action" value="wc_tn_finalize"><input type="hidden" name="property_id" value="<?php echo (int) $ctx['property_id']; ?>"><input type="hidden" name="expense_year" value="<?php echo (int) $ctx['expense_year']; ?>"><input type="hidden" name="billing_year" value="<?php echo (int) $ctx['billing_year']; ?>"><?php wp_nonce_field('wc_tn_finalize'); ?>
                <button class="wc-tn-button wc-tn-button-finalize" type="submit">Mark DRAFTED for Invoices</button><span>DRAFTED charges are available to invoice generation.</span>
            </form>
        <?php elseif ($drafted): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wc-tn-inline-form wc-tn-reopen-form"><?php echo wc_tn_manager_scope_hidden_input(); ?>
                <input type="hidden" name="action" value="wc_tn_reopen"><input type="hidden" name="property_id" value="<?php echo (int) $ctx['property_id']; ?>"><input type="hidden" name="expense_year" value="<?php echo (int) $ctx['expense_year']; ?>"><input type="hidden" name="billing_year" value="<?php echo (int) $ctx['billing_year']; ?>"><?php wp_nonce_field('wc_tn_reopen'); ?>
                <button class="wc-tn-button wc-tn-button-secondary" type="submit">Return to UNDRAFTED</button><span>UNDRAFTED records can be edited.</span>
            </form>
        <?php endif; ?>
    </section>
    <?php return ob_get_clean();
}

function wc_tn_charge_label($type) {
    return ['CAM'=>'CAM','INSURANCE'=>'Insurance','PROPERTY_TAX'=>'Property Tax'][$type] ?? $type;
}

function wc_tn_allocations_html($ctx) {
    $rec = wc_tn_get_reconciliation($ctx['property_id'], $ctx['expense_year'], $ctx['billing_year']);
    if (!$rec) return '<section class="wc-tn-card wc-tn-card-charges"><div class="wc-tn-card-heading"><h2>Triple Net Charges</h2></div><div class="wc-tn-empty">Save the Triple Net costs to calculate tenant and WoodCrest charges.</div></section>';
    $groups = wc_tn_get_grouped_allocations((int) $rec->nnn_reconciliation_id);
    $drafted = wc_tn_status_is_drafted($rec->status);
    $woodcrest = wc_tn_woodcrest_charge_summary($rec, $groups);

    ob_start(); ?>
    <section class="wc-tn-card wc-tn-card-charges">
        <div class="wc-tn-card-heading wc-tn-heading-split"><div><h2>Triple Net Charges</h2><p>Review tenant and WoodCrest monthly responsibility and adjust tenant charges only when needed.</p></div><div class="wc-tn-table-count"><?php echo count($groups); ?> tenant record<?php echo count($groups) === 1 ? '' : 's'; ?></div></div>
        <?php if (!$groups): ?>
            <div class="wc-tn-info">No tenant occupancies overlap the selected billing year. WoodCrest carries the full Triple Net responsibility.</div>
        <?php else: ?>
            <div class="wc-tn-toolbar"><input type="search" class="wc-tn-table-search" placeholder="Search tenants or suites" aria-label="Search tenant charges"></div>
        <?php endif; ?>
            <div class="wc-tn-table-wrap">
                <table class="wc-tn-table wc-tn-allocations-table"><thead><tr><th>Responsible Party</th><th>Suite</th><th>Sq. Ft.</th><th>Share</th><th>CAM / Mo.</th><th>Insurance / Mo.</th><th>Property Tax / Mo.</th><th>Total / Mo.</th></tr></thead><tbody>
                <?php foreach ($groups as $group):
                    $name = trim((string) $group['display_name']) ?: $group['user_login'];
                    $pct = null; $monthly = ['CAM'=>0,'INSURANCE'=>0,'PROPERTY_TAX'=>0];
                    foreach ($monthly as $type => $_) {
                        if (!empty($group['allocations'][$type])) {
                            $a = $group['allocations'][$type];
                            $monthly[$type] = (float) $a->monthly_amount;
                            if ($pct === null) $pct = (float) $a->allocation_percentage;
                        }
                    }
                    $total = array_sum($monthly);
                ?>
                    <tr data-wc-tn-search-row="<?php echo (int) $group['occupancy_id']; ?>"><td><strong><?php echo esc_html($name); ?></strong><br><small><?php echo esc_html($group['user_login']); ?></small></td><td><?php echo esc_html($group['suite_label']); ?></td><td><?php echo esc_html(number_format($group['square_feet'], 2)); ?></td><td><?php echo $pct !== null ? esc_html(wc_tn_percent($pct)) : '—'; ?></td><td><?php echo esc_html(wc_tn_money($monthly['CAM'])); ?></td><td><?php echo esc_html(wc_tn_money($monthly['INSURANCE'])); ?></td><td><?php echo esc_html(wc_tn_money($monthly['PROPERTY_TAX'])); ?></td><td><strong><?php echo esc_html(wc_tn_money($total)); ?></strong></td></tr>
                <?php endforeach;
                    $wc_monthly = $woodcrest['monthly'];
                    $wc_total = array_sum($wc_monthly);
                ?>
                    <tr class="wc-tn-owner-row" data-wc-tn-search-row="woodcrest"><td><strong>WoodCrest Portion</strong><br><small>Vacancy + owner responsibility</small></td><td>Unallocated / Owner</td><td><?php echo esc_html(number_format((float) $woodcrest['vacant_sqft'], 2)); ?></td><td><?php echo esc_html(wc_tn_percent((float) $woodcrest['effective_share'])); ?></td><td><?php echo esc_html(wc_tn_money($wc_monthly['CAM'])); ?></td><td><?php echo esc_html(wc_tn_money($wc_monthly['INSURANCE'])); ?></td><td><?php echo esc_html(wc_tn_money($wc_monthly['PROPERTY_TAX'])); ?></td><td><strong><?php echo esc_html(wc_tn_money($wc_total)); ?></strong></td></tr>
                </tbody></table>
            </div>

        <?php if ($groups): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wc-tn-adjustments-form wc-tn-track-form"><?php echo wc_tn_manager_scope_hidden_input(); ?>
                <input type="hidden" name="action" value="wc_tn_save_allocations"><input type="hidden" name="property_id" value="<?php echo (int) $ctx['property_id']; ?>"><input type="hidden" name="expense_year" value="<?php echo (int) $ctx['expense_year']; ?>"><input type="hidden" name="billing_year" value="<?php echo (int) $ctx['billing_year']; ?>"><?php wp_nonce_field('wc_tn_save_allocations'); ?>
                <div class="wc-tn-adjustment-heading"><h3>Manage Tenant Charges</h3><p>Pro-Rata is the default. Use an adjustment or fixed amount when needed.</p></div>
                <?php foreach ($groups as $group):
                    $name = trim((string) $group['display_name']) ?: $group['user_login'];
                ?>
                    <details class="wc-tn-tenant-adjustment" data-wc-tn-search-details="<?php echo (int) $group['occupancy_id']; ?>">
                        <summary><span><strong><?php echo esc_html($name); ?></strong> — Suite <?php echo esc_html($group['suite_label']); ?></span><span><?php echo esc_html(number_format($group['square_feet'], 2)); ?> sq. ft.</span></summary>
                        <div class="wc-tn-charge-grid">
                            <?php foreach (['CAM','INSURANCE','PROPERTY_TAX'] as $type):
                                $a = $group['allocations'][$type] ?? null;
                                if (!$a || !$a->nnn_allocation_id) continue;
                                $base = round(((float) $a->total_cost * (float) $a->allocation_percentage) / 12, 2);
                                $method = $a->allocation_method === 'FIXED' ? 'FIXED' : 'PRO_RATA';
                                $adjustment = (float) $a->adjustment_amount;
                                $fixed = $a->fixed_monthly_amount;
                                $reason = (string) $a->override_reason;
                            ?>
                                <div class="wc-tn-charge-card" data-base="<?php echo esc_attr(number_format($base, 2, '.', '')); ?>">
                                    <h4><?php echo esc_html(wc_tn_charge_label($type)); ?></h4>
                                    <div class="wc-tn-calculated-line"><span>Calculated Pro-Rata</span><strong><?php echo esc_html(wc_tn_money($base)); ?></strong></div>
                                    <div class="wc-tn-field"><label>Charge Method</label><select class="wc-tn-method" name="alloc[<?php echo (int) $a->nnn_allocation_id; ?>][method]" <?php disabled($drafted); ?>><option value="PRO_RATA" <?php selected($method, 'PRO_RATA'); ?>>Pro-Rata</option><option value="FIXED" <?php selected($method, 'FIXED'); ?>>Fixed Amount</option></select></div>
                                    <div class="wc-tn-field"><label>Adjustment / Month</label><input class="wc-tn-adjustment-input" name="alloc[<?php echo (int) $a->nnn_allocation_id; ?>][adjustment_amount]" type="number" step="0.01" value="<?php echo esc_attr(number_format($adjustment, 2, '.', '')); ?>" <?php disabled($drafted || $method === 'FIXED'); ?>><small>Positive or negative amount.</small></div>
                                    <div class="wc-tn-field"><label>Fixed Amount / Month</label><input class="wc-tn-fixed-input" name="alloc[<?php echo (int) $a->nnn_allocation_id; ?>][fixed_monthly_amount]" type="number" min="0" step="0.01" value="<?php echo $fixed === null ? '' : esc_attr(number_format((float) $fixed, 2, '.', '')); ?>" <?php disabled($drafted || $method !== 'FIXED'); ?>></div>
                                    <div class="wc-tn-field"><label>Reason</label><input class="wc-tn-reason-input" name="alloc[<?php echo (int) $a->nnn_allocation_id; ?>][override_reason]" type="text" maxlength="255" value="<?php echo esc_attr($reason); ?>" placeholder="Required for manual changes" <?php disabled($drafted); ?>></div>
                                    <div class="wc-tn-final-line"><span>Monthly Charge</span><strong class="wc-tn-final-preview"><?php echo esc_html(wc_tn_money($a->monthly_amount)); ?></strong></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
                <?php if (!$drafted): ?><div class="wc-tn-actions"><button class="wc-tn-button wc-tn-track-dirty" type="submit" data-idle-label="Save Charge Changes" data-ready-label="Save Charge Changes">Save Charge Changes</button></div><?php else: ?><div class="wc-tn-info drafted">Return the year to UNDRAFTED to edit tenant charges.</div><?php endif; ?>
            </form>
        <?php endif; ?>
    </section>
    <?php return ob_get_clean();
}

function wc_tn_summary_html($ctx) {
    global $wpdb;
    $t = wc_tn_tables();
    $ids = wc_tn_manager_property_ids();
    if (!$ids) return '';
    $ph = implode(',', array_fill(0, count($ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT r.nnn_reconciliation_id, r.property_id, r.expense_year, r.billing_year,
                r.total_allocable_sqft, r.status, r.finalized_at, p.property_name,
                MAX(CASE WHEN e.expense_type='CAM' THEN e.total_cost END) AS cam_total,
                MAX(CASE WHEN e.expense_type='INSURANCE' THEN e.total_cost END) AS insurance_total,
                MAX(CASE WHEN e.expense_type='PROPERTY_TAX' THEN e.total_cost END) AS property_tax_total
         FROM {$t['reconciliation']} r JOIN {$t['property']} p ON p.property_id = r.property_id
         LEFT JOIN {$t['expense']} e ON e.nnn_reconciliation_id = r.nnn_reconciliation_id
         WHERE r.property_id IN ($ph)
         GROUP BY r.nnn_reconciliation_id, r.property_id, r.expense_year, r.billing_year,
                  r.total_allocable_sqft, r.status, r.finalized_at, p.property_name
         ORDER BY r.billing_year DESC, p.property_name, r.expense_year DESC",
        ...$ids
    ));
    ob_start(); ?>
    <section class="wc-tn-card wc-tn-card-history"><div class="wc-tn-card-heading"><h2>Triple Net History</h2><p>Past Triple Net records.</p></div>
    <?php if (!$rows): ?><div class="wc-tn-empty">No Triple Net years have been created yet.</div><?php else:
        $history_cells = [];
        foreach ($rows as $row) {
            $url = add_query_arg(['wc_tn_property'=>(int)$row->property_id,'wc_tn_expense_year'=>(int)$row->expense_year,'wc_tn_billing_year'=>(int)$row->billing_year], get_permalink() ?: home_url('/'));
            $history_cells[] = [
                esc_html($row->property_name), (int)$row->expense_year, (int)$row->billing_year,
                esc_html(number_format((float)$row->total_allocable_sqft, 2)),
                esc_html(wc_tn_money($row->cam_total ?: 0)), esc_html(wc_tn_money($row->insurance_total ?: 0)),
                esc_html(wc_tn_money($row->property_tax_total ?: 0)),
                '<span class="wc-tn-status '.(wc_tn_status_is_drafted($row->status)?'drafted':'undrafted').'">'.esc_html(wc_tn_status_label($row->status)).'</span>',
                '<a class="wc-tn-link" href="'.esc_url($url).'">Open</a>'
            ];
        }
        echo woodcrest_fe_property_grouped_table(['Property','Expense Year','Billing Year','Allocable Sq. Ft.','CAM','Insurance','Property Tax','Status','Action'], $history_cells, 0, ['date_column'=>1]);
    ?>
    <?php endif; ?></section>
    <?php return ob_get_clean();
}

function wc_tn_shortcode_wrap($content, $show_notice = true) {
    return '<div class="wc-tn-page wc-role-manager-content">' . ($show_notice ? wc_tn_notice_html() : '') . $content . '</div>';
}

function wc_tn_shortcode_full() {
    if ($guard = wc_tn_access_guard()) return $guard;
    $ctx = wc_tn_context();
    return wc_tn_shortcode_wrap(wc_tn_selector_html($ctx) . wc_tn_manage_html($ctx) . woodcrest_ocr_annual_reference_html($ctx['property_id'], $ctx['expense_year']) . wc_tn_allocations_html($ctx) . wc_tn_summary_html($ctx));
}
function wc_tn_shortcode_selector() {
    if ($guard = wc_tn_access_guard()) return $guard;
    $ctx = wc_tn_context();
    return wc_tn_shortcode_wrap(wc_tn_selector_html($ctx));
}
function wc_tn_shortcode_setup() {
    if ($guard = wc_tn_access_guard()) return $guard;
    $ctx = wc_tn_context();
    return wc_tn_shortcode_wrap(wc_tn_manage_html($ctx) . woodcrest_ocr_annual_reference_html($ctx['property_id'], $ctx['expense_year']));
}
function wc_tn_shortcode_charges() {
    if ($guard = wc_tn_access_guard()) return $guard;
    $ctx = wc_tn_context();
    return wc_tn_shortcode_wrap(wc_tn_allocations_html($ctx));
}
function wc_tn_shortcode_history() {
    if ($guard = wc_tn_access_guard()) return $guard;
    $ctx = wc_tn_context();
    return wc_tn_shortcode_wrap(wc_tn_summary_html($ctx));
}


function wc_tn_shortcode_manage() {
    if ($guard = wc_tn_access_guard()) return $guard;
    $ctx = wc_tn_context();
    return wc_tn_shortcode_wrap(wc_tn_selector_html($ctx) . wc_tn_manage_html($ctx) . woodcrest_ocr_annual_reference_html($ctx['property_id'], $ctx['expense_year']));
}
function wc_tn_shortcode_allocations() { return wc_tn_shortcode_charges(); }
function wc_tn_shortcode_summary() { return wc_tn_shortcode_history(); }
