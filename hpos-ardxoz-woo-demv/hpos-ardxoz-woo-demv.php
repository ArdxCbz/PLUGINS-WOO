<?php
/**
 * Plugin Name: HPOS Ardxoz Woo DEMV
 * Description: Gestión de depósitos bancarios, búsqueda por guía y auto-fill de envío. Compatible HPOS.
 * Version:     3.21
 * Author:      Ardxoz
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HAWD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HAWD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HAWD_VERSION', '3.21');

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Declarar compatibilidad con HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Cargar dependencias
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-permisos.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-meta.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-calculator.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-query.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-admin.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-ajax.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-search.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-checkout.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-config.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-caja.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-traspasos.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-depositos-express.php';
require_once HAWD_PLUGIN_DIR . 'includes/class-demv-objetivos.php';

// Crear tablas al activar el plugin
register_activation_hook(__FILE__, function () {
    HPOS_Ardxoz_Woo_DEMV_Caja::ensure_table();
    HPOS_Ardxoz_Woo_DEMV_Objetivos::ensure_table();
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
    HPOS_Ardxoz_Woo_DEMV_Traspasos::init();
    HPOS_Ardxoz_Woo_DEMV_Depositos_Express::init();
    HPOS_Ardxoz_Woo_DEMV_Objetivos::init();
});

// Schema de Objetivos: aplica dbDelta + migración solo cuando cambia DB_VERSION.
// La primera vez crea la tabla; en upgrades reaplica dbDelta para añadir columnas
// nuevas (objetivo_piso/objetivo_techo) y migra datos legacy.
add_action('admin_init', function () {
    if (!class_exists('HPOS_Ardxoz_Woo_DEMV_Objetivos')) return;
    $current = (string) get_option('hawd_objetivos_db_version', '');
    if ($current === HPOS_Ardxoz_Woo_DEMV_Objetivos::DB_VERSION) return;
    HPOS_Ardxoz_Woo_DEMV_Objetivos::ensure_table();
    update_option('hawd_objetivos_db_version', HPOS_Ardxoz_Woo_DEMV_Objetivos::DB_VERSION, false);
    // Mantenemos también el flag legacy para no re-ejecutar la rama antigua si aún se invoca.
    update_option('hawd_objetivos_db_ready', '1', false);
});

// Schema de Caja: aplica dbDelta solo cuando cambia DB_VERSION.
// Evita ejecutar dbDelta en cada render/handler de la página de Caja.
add_action('admin_init', function () {
    if (!class_exists('HPOS_Ardxoz_Woo_DEMV_Caja')) return;
    $current = (string) get_option('hawd_caja_db_version', '');
    if ($current === HPOS_Ardxoz_Woo_DEMV_Caja::DB_VERSION) return;
    HPOS_Ardxoz_Woo_DEMV_Caja::ensure_table();
    update_option('hawd_caja_db_version', HPOS_Ardxoz_Woo_DEMV_Caja::DB_VERSION, false);
});

/**
 * Invalida caches del plugin cuando un pedido cambia (creado, actualizado, borrado).
 * - hawd_stats_version: bump → todas las claves `hawd_stats_*` quedan obsoletas.
 * - Listas cacheadas (métodos de envío/pago, departamentos): borrado directo.
 */
$hawd_invalidate_caches = function () {
    update_option('hawd_stats_version', (string) microtime(true), false);
    delete_transient('hawd_shipping_methods');
    delete_transient('hawd_payment_methods');
    delete_transient('hawd_billing_states');
};
add_action('woocommerce_new_order',                $hawd_invalidate_caches);
add_action('woocommerce_after_order_object_save',  $hawd_invalidate_caches);
add_action('woocommerce_delete_order',             $hawd_invalidate_caches);
add_action('woocommerce_trash_order',              $hawd_invalidate_caches);
