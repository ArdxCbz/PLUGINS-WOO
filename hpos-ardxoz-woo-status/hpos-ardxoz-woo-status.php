<?php
/**
 * Plugin Name: HPOS Ardxoz Woo Status
 * Description: Añade estados de pedido personalizados compatibles con WooCommerce High-Performance Order Tables.
 * Version:     3.0.1
 * Author:      Ardxoz
 * Text Domain: haw
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Declarar compatibilidad con HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Cargar traducciones (opcional)
// add_action( 'plugins_loaded', function() {
//     load_plugin_textdomain( 'whs', false, dirname( __FILE__ ) . '/languages' );
// } );

// Incluir las clases
require_once __DIR__ . '/inc/class-hpos-ardxoz-woo-status-cpt.php';
require_once __DIR__ . '/inc/class-hpos-ardxoz-woo-status-register.php';
require_once __DIR__ . '/inc/class-hpos-ardxoz-woo-status-list.php';
require_once __DIR__ . '/inc/class-hpos-ardxoz-woo-status-admin-actions.php';

// Inicializar las clases
add_action('plugins_loaded', function () {
    new HPOS_Ardxoz_Woo_Status_CPT();
    new HPOS_Ardxoz_Woo_Status_Register();
    new HPOS_Ardxoz_Woo_Status_List();
    new HPOS_Ardxoz_Woo_Status_Admin_Actions();
});