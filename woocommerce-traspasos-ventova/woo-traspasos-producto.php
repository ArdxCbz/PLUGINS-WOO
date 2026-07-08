<?php
/*
Plugin Name: Woo Traspasos de Producto Ventova
Description: Mueve stock entre sucursales usando variaciones; soporta también traspasos solo con descripción (envío de bienes por mensajería).
Version: 4.10
Author: Ardx
*/

// Evita acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Verifica si WooCommerce está activo
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Carga las clases del plugin
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-tp-config.php'; // Config first
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-tp-install.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-tp-mappings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-tp-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-tp-ajax.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-tp-csv-exporter.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-tp-api.php';

// Inicializa las clases
WC_TP_Install::init();
WC_TP_Mappings::init();
WC_TP_Admin::init();
WC_TP_Ajax::init();
WC_TP_CSV_Exporter::init();