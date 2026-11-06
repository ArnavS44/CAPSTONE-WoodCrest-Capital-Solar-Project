<?php
if (!defined('ABSPATH')) exit;

function woodcrest_suite_occupancy_chart_shortcode() {
    if (!is_user_logged_in()) return '<div class="woodcrest-card wc-graph-access-note">Sign in to see suite availability.</div>';
    $admin = current_user_can('manage_options');
    $context = $admin ? woodcrest_fe_preview_context() : [];
    if ($admin && ($context['role'] ?? '') === 'tenant') {
        return '<div class="woodcrest-card wc-graph-access-note">Suite availability is available on the administrator and property manager dashboards.</div>';
    }
    $manager_id = 0;
    if ($admin && ($context['role'] ?? '') === 'manager') {
        $manager_id = absint($context['user_id']);
    } elseif (!$admin && woodcrest_fe_is_property_manager(get_current_user_id())) {
        $manager_id = get_current_user_id();
    } elseif (!$admin) {
        return '<div class="woodcrest-card wc-graph-access-note">This chart is available to administrators and property managers.</div>';
    }
    $role = $admin && !$manager_id ? 'wc-role-admin' : 'wc-role-manager-content';
    global $wpdb;
    $t = woodcrest_fe_tables();
    $ids = $manager_id ? array_map('intval', woodcrest_fe_manager_property_ids($manager_id)) : [];
    $total = 0;
    $assigned = 0;
    if (!$manager_id || $ids) {
        $where = $manager_id ? 'WHERE s.property_id IN (' . implode(',', $ids) . ')' : '';
        $today = current_time('Y-m-d');
        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total_suites,
                COALESCE(SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM `{$t['suite_occupancy']}` so
                    WHERE so.suite_id=s.suite_id AND so.start_date<=%s
                      AND (so.end_date IS NULL OR so.end_date >= %s)
                ) THEN 1 ELSE 0 END),0) AS assigned_suites
             FROM `{$t['suite']}` s {$where}", $today, $today
        ));
        $total = max(0, (int)($counts->total_suites ?? 0));
        $assigned = min($total, max(0, (int)($counts->assigned_suites ?? 0)));
    }
    $vacant = $total - $assigned;
    $percent = $total ? round($assigned / $total * 100) : 0;
    ob_start(); ?>
    <div class="woodcrest-fe-module wc-graph-module wc-suite-occupancy-module <?php echo esc_attr($role); ?>" data-graph-shortcode="woodcrest_suite_occupancy_chart">
      <section class="woodcrest-card wc-graph-card">
        <header class="wc-graph-top"><h2>Suite availability</h2></header>
        <div class="wc-occupancy-content">
          <div class="wc-occupancy-donut" style="--wc-occupancy-percent:<?php echo (int)$percent; ?>%;" role="img" aria-label="<?php echo esc_attr(sprintf('%d assigned and %d vacant out of %d total suites', $assigned, $vacant, $total)); ?>">
            <div class="wc-occupancy-donut-hole"><strong><?php echo (int)$percent; ?>%</strong><span>Assigned</span></div>
          </div>
          <div class="wc-occupancy-details">
            <div class="wc-occupancy-detail"><span class="wc-occupancy-marker wc-occupancy-assigned"></span><span>Assigned</span><strong><?php echo esc_html(number_format_i18n($assigned)); ?></strong></div>
            <div class="wc-occupancy-detail"><span class="wc-occupancy-marker wc-occupancy-vacant"></span><span>Vacant</span><strong><?php echo esc_html(number_format_i18n($vacant)); ?></strong></div>
            <div class="wc-occupancy-total"><span>Total suites</span><strong><?php echo esc_html(number_format_i18n($total)); ?></strong></div>
          </div>
        </div>
        <?php if (!$total): ?><p class="wc-graph-empty">No suites are available within this property scope.</p><?php endif; ?>
      </section>
    </div>
    <?php return ob_get_clean();
}
add_shortcode('woodcrest_suite_occupancy_chart', 'woodcrest_suite_occupancy_chart_shortcode');
