<?php
/**
 * Plugin Name: HPOS Ardxoz Woo DEMV
 * Description: Cálculo de importe a depositar, bulk fill de datos bancarios y búsqueda por guía/depósito. Compatible HPOS.
 * Version:     1.3
 * Author:      Ardxoz
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HAWD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HAWD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HAWD_VERSION', '1.3');

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Declarar compatibilidad con HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Cargar dependencias
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-calculator.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-search.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-bulk.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-checkout.php';

// Inicializar
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }

    HPOS_Ardxoz_Woo_DEMV_Search::init();
    HPOS_Ardxoz_Woo_DEMV_Bulk::init();
    HPOS_Ardxoz_Woo_DEMV_Checkout::init();
});
