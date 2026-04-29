<?php
/*
Plugin Name: Woodcrest Power DB
Description:Makes Wordpress know how to read and display our data.
Version: 0.1
*/

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/db.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-pages.php';
require_once plugin_dir_path(__FILE__) . 'includes/shortcodes.php';

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'woodcrest-power-style',
        plugin_dir_url(__FILE__) . 'assets/style.css',
        [],
        '1.3' 
    );
});
