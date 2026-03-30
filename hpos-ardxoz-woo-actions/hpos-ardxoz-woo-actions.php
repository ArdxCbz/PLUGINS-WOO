<?php
/**
 * Plugin Name: HPOS Ardxoz Woo Actions
 * Description: Botones de acción y modales para vendedor y administrador en lista de pedidos HPOS.
 * Version:     1.0
 * Author:      Ardxoz
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HAWA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HAWA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HAWA_VERSION', '1.0');
define('HAWA_DEFAULT_COSTO_ENVIO', 12.48);

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Declarar compatibilidad con HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Cargar dependencias
require_once HAWA_PLUGIN_DIR . 'includes/class-actions-assets.php';
require_once HAWA_PLUGIN_DIR . 'includes/class-actions-columns.php';
require_once HAWA_PLUGIN_DIR . 'includes/class-actions-modals.php';
require_once HAWA_PLUGIN_DIR . 'includes/class-actions-ajax.php';

// Inicializar
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }

    HPOS_Ardxoz_Woo_Actions_Assets::init();
    HPOS_Ardxoz_Woo_Actions_Columns::init();
    HPOS_Ardxoz_Woo_Actions_Modals::init();
    HPOS_Ardxoz_Woo_Actions_Ajax::init();
});
