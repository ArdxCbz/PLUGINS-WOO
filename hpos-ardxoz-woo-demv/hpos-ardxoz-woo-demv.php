<?php
/**
 * Plugin Name: HPOS Ardxoz Woo DEMV
 * Description: Gestión de depósitos bancarios, búsqueda por guía y auto-fill de envío. Compatible HPOS.
 * Version:     3.1
 * Author:      Ardxoz
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HAWD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HAWD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HAWD_VERSION', '3.1');

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Declarar compatibilidad con HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Cargar dependencias
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-meta.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-calculator.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-query.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-admin.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-ajax.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-search.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-checkout.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-config.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-caja.php';

// Crear tabla de caja al activar el plugin
register_activation_hook(__FILE__, function () {
    HPOS_Ardxoz_Woo_DEMV_Caja::ensure_table();
});

// Inicializar
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }

    HPOS_Ardxoz_Woo_DEMV_Admin::init();
    HPOS_Ardxoz_Woo_DEMV_Ajax::init();
    HPOS_Ardxoz_Woo_DEMV_Search::init();
    HPOS_Ardxoz_Woo_DEMV_Checkout::init();
    HPOS_Ardxoz_Woo_DEMV_Caja::init();
    HPOS_Ardxoz_Woo_DEMV_Config::init();
});
