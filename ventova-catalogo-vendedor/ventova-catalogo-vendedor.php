<?php
/**
 * Plugin Name: Ventova — Catálogo para Vendedoras
 * Description: Catálogo de productos para el rol vendedor con stock por sucursal correcto (simples y variables), buscador por nombre y SKU, paginación, precio y enlace a la ficha. Reemplaza la página "Productos" que vivía en el tema hijo.
 * Version: 0.8.0
 * Author: Ventova
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ventova-catalogo-vendedor
 *
 * @package Ventova_Catalogo_Vendedor
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VCV_VERSION', '0.8.0');
define('VCV_FILE', __FILE__);
define('VCV_PATH', plugin_dir_path(__FILE__));
define('VCV_URL', plugin_dir_url(__FILE__));

// Compatibilidad HPOS (el plugin no toca pedidos, pero se declara para no
// aparecer como incompatible en WooCommerce → Estado → Características).
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            VCV_FILE,
            true
        );
    }
});

/**
 * Carga los módulos. WooCommerce es obligatorio: sin él no hay productos que
 * listar y las clases de sucursales no tienen de dónde leer.
 */
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p><strong>Ventova Catálogo:</strong> requiere WooCommerce activo.</p></div>';
        });
        return;
    }

    require_once VCV_PATH . 'includes/class-vcv-sucursales.php';
    require_once VCV_PATH . 'includes/class-vcv-query.php';
    require_once VCV_PATH . 'includes/class-vcv-permisos.php';
    require_once VCV_PATH . 'includes/class-vcv-resumen.php';
    require_once VCV_PATH . 'includes/class-vcv-catalogo.php';
    require_once VCV_PATH . 'includes/class-vcv-menu.php';
    require_once VCV_PATH . 'includes/class-vcv-columns.php';

    if (is_admin()) {
        VCV_Menu::init();
        VCV_Resumen::init();
        VCV_Columns::init();
    }
}, 20);
