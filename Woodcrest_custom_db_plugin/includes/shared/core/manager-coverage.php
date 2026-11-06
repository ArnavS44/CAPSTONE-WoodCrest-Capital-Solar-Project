<?php
if (!defined('ABSPATH')) exit;

function woodcrest_auto_assign_manager_to_property($user_id, $property_id) {
    global $wpdb;
    $t = woodcrest_fe_tables();
    $user_id = absint($user_id);
    $property_id = absint($property_id);
    if (!$user_id || !$property_id || !get_userdata($user_id)) return false;
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT access_id, start_date, end_date FROM {$t['user_property_access']}
         WHERE user_id = %d AND property_id = %d AND access_scope = 'PROPERTY_MANAGER'
         ORDER BY access_id DESC LIMIT 1", $user_id, $property_id
    ));
    if ($existing) {
        $today = current_time('Y-m-d');
        if (($existing->start_date === null || $existing->start_date <= $today)
            && ($existing->end_date === null || $existing->end_date > $today)) {
            return true;
        }
        return $wpdb->update($t['user_property_access'],
            ['start_date' => null, 'end_date' => null], ['access_id' => (int)$existing->access_id]) !== false;
    }
    return $wpdb->insert($t['user_property_access'], [
        'user_id' => $user_id,
        'property_id' => $property_id,
        'access_scope' => 'PROPERTY_MANAGER',
        'start_date' => null,
        'end_date' => null,
        'created_at' => current_time('mysql'),
    ]) !== false;
}

function woodcrest_auto_assign_new_property($property_id) {
    $property_id = absint($property_id);
    if (!$property_id) return;
    $managers = get_users([
        'meta_key' => '_woodcrest_auto_assign_new_properties',
        'meta_value' => '1',
        'fields' => 'ID',
    ]);
    foreach ($managers as $manager_id) {
        woodcrest_auto_assign_manager_to_property((int)$manager_id, $property_id);
    }
}
