<?php
/**
 * Plugin Name: HPOS Ardxoz Woo Orders
 * Description: Columnas personalizadas en lista de pedidos HPOS. Fallback a meta keys ACF legacy para transición.
 * Version: 7.4
 * Author: Ardxoz
 */

if (!defined('ABSPATH')) {
    exit;
}

// Constantes
define('HAWO_PATH', plugin_dir_path(__FILE__));
define('HAWO_URL', plugin_dir_url(__FILE__));

// Debug
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('[HAWO] Plugin loaded: HPOS Ardxoz Woo Orders');
}

// Auto-carga de todas las clases en includes/
foreach (glob(HAWO_PATH . 'includes/*.php') as $file) {
    require_once $file;
}

// Inicializar columnas
add_action('plugins_loaded', ['HPOS\\Ardxoz\\Woo\\Orders\\Column_Manager', 'init']);


