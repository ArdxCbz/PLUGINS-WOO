<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// BEGIN ENQUEUE PARENT ACTION
// AUTO GENERATED - Do not modify or remove comment markers above or below:

if ( !function_exists( 'chld_thm_cfg_locale_css' ) ):
    function chld_thm_cfg_locale_css( $uri ){
        if ( empty( $uri ) && is_rtl() && file_exists( get_template_directory() . '/rtl.css' ) )
            $uri = get_template_directory_uri() . '/rtl.css';
        return $uri;
    }
endif;
add_filter( 'locale_stylesheet_uri', 'chld_thm_cfg_locale_css' );

// END ENQUEUE PARENT ACTION
// #### CAMBIOS WEB VENTOVA POR ARMANDO CABEZAS ####

// Declarar compatibilidad HPOS con WooCommerce
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            get_stylesheet_directory() . '/style.css'
        );
    }
});

// Inclusiones de funcionalidades modularizadas
require_once get_stylesheet_directory() . '/inc/admin-cleanup.php';
require_once get_stylesheet_directory() . '/inc/woocommerce-product-columns.php';
require_once get_stylesheet_directory() . '/inc/woocommerce-custom.php';
require_once get_stylesheet_directory() . '/inc/admin-orders-autoreload.php';