<?php
/*
Plugin Name: HPOS Ardxoz Woo Shipping Export
Description: Exporta CSV de envíos filtrado por mes y métodos de envío específicos (SUECIA, CBS, ENCOMIENDA). Compatible con HPOS.
Version: 1.0
Author: Ventova
Requires Plugins: woocommerce
*/

if (!defined('ABSPATH')) {
    exit;
}

define('HAWSE_VERSION', '1.0');
define('HAWSE_PATH', plugin_dir_path(__FILE__));
define('HAWSE_FILE', __FILE__);

// Declare HPOS compatibility.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Load plugin class.
add_action('plugins_loaded', function () {
    require_once HAWSE_PATH . 'includes/class-shipping-export.php';
    HPOS_Ardxoz_Woo_Shipping_Export::init();
});
