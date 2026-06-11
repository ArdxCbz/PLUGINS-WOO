<?php
/*
Plugin Name: WooCommerce Inventario por Sucursal VENTOVA
Description: Submenú "Inventario Ventova" bajo Productos. Conteo físico por sucursal con persistencia mensual, registro de mermas/defectuosos, proveedores, compras (suman stock y actualizan costos al recibir), kardex (ledger central de movimientos con saldo corrido) y exportación CSV. Incluye productos de la categoría TIENDA (también los ocultos) bajo Santa Cruz.
Version: 3.49
Author: Ardx
Requires Plugins: woocommerce
*/

if (!defined('ABSPATH')) {
    exit;
}

define('IEM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IEM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IEM_VERSION', '3.49');

require_once IEM_PLUGIN_DIR . 'includes/class-iem-schema.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-permisos.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-sucursales.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-collector.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-csv.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-counts.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-kardex.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-mermas.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-suppliers.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-purchases.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-import-costs.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-ajax.php';
require_once IEM_PLUGIN_DIR . 'includes/class-iem-admin.php';

// ── Limpieza one-shot del cron + envío por correo del legado v1.x ──
add_action('init', 'iem_clear_legacy_cron_once');
function iem_clear_legacy_cron_once()
{
    if (get_option('iem_legacy_cron_cleared') === '2') {
        return;
    }
    wp_clear_scheduled_hook('iem_check_daily_inventario');
    wp_clear_scheduled_hook('iem_enviar_inventario_ciudades');
    delete_option('iem_last_sent_date');
    update_option('iem_legacy_cron_cleared', '2', false);
}

// ── Instalación / upgrade del esquema propio (v3.0+) ──
register_activation_hook(__FILE__, ['IEM_Schema', 'install']);
add_action('init', ['IEM_Schema', 'maybe_upgrade']);

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('iem_check_daily_inventario');
    wp_clear_scheduled_hook('iem_enviar_inventario_ciudades');
    // No se borran las tablas propias para preservar el histórico de conteos
    // y el registro de mermas. La desinstalación (uninstall.php, futuro)
    // sería el lugar para limpieza opt-in.
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    IEM_Admin::init();
});
