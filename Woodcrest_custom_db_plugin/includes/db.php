<?php
if (!defined('ABSPATH')) exit;

global $wpdb;
global $woodcrest_db;
$woodcrest_db = $wpdb;

// needed for conversion to wordpress
$woodcrest_db->app_user             = $wpdb->users; 
$woodcrest_db->invoice              = $wpdb->prefix . 'wc_invoice';
$woodcrest_db->invoice_line_item    = $wpdb->prefix . 'wc_invoice_line_item';
$woodcrest_db->master_utility_bill  = $wpdb->prefix . 'wc_master_utility_bill';
$woodcrest_db->meter                = $wpdb->prefix . 'wc_meter';
$woodcrest_db->meter_hierarchy       = $wpdb->prefix . 'wc_meter_hierarchy';
$woodcrest_db->meter_responsibility  = $wpdb->prefix . 'wc_meter_responsibility';
$woodcrest_db->payment              = $wpdb->prefix . 'wc_payment';
$woodcrest_db->property             = $wpdb->prefix . 'wc_property';
$woodcrest_db->rate_schedule        = $wpdb->prefix . 'wc_rate_schedule';
$woodcrest_db->suite                = $wpdb->prefix . 'wc_suite';
$woodcrest_db->suite_occupancy       = $wpdb->prefix . 'wc_suite_occupancy';
$woodcrest_db->user_property_access  = $wpdb->prefix . 'wc_user_property_access';
$woodcrest_db->utility_account      = $wpdb->prefix . 'wc_utility_account';
$woodcrest_db->utility_reading      = $wpdb->prefix . 'wc_utility_reading';


