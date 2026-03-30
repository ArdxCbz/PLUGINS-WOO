<?php
/**
 * Plugin Name: HPOS Ardxoz Woo Print Note
 * Description: Imprime notas de entrega compactas para pedidos de WooCommerce en impresoras térmicas (80mm) con soporte HPOS.
 * Version: 3.0
 * Author: Ardxoz
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HAW_PRINT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HAW_PRINT_PLUGIN_URL', plugin_dir_url(__FILE__));

use Automattic\WooCommerce\Utilities\FeaturesUtil;

add_action('before_woocommerce_init', function () {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

require_once HAW_PRINT_PLUGIN_DIR . 'includes/class-settings.php';
require_once HAW_PRINT_PLUGIN_DIR . 'includes/class-print-manager.php';

// Link "Ajustes" en el listado de plugins
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('options-general.php?page=haw-print-nota-entrega');
    array_unshift($links, '<a href="' . esc_url($url) . '">Ajustes</a>');
    return $links;
});

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        HPOS_Ardxoz_Woo_Print_Settings::init();
        HPOS_Ardxoz_Woo_Print_Manager::init();
    } else {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>HPOS Ardxoz Woo Print Note</strong> requiere WooCommerce activo.</p></div>';
        });
    }
});
