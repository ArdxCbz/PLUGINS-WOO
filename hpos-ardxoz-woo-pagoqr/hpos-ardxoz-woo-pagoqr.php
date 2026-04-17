<?php
/*
 * Plugin Name: Pago QR HPOS
 * Description: Pasarela de pago mediante código QR compatible con HPOS. Permite adjuntar comprobante de pago durante el pedido.
 * Version: 1.3.0
 * Author: Ventova
 * Text Domain: hpos-ardxoz-pagoqr
 * Requires at least: 5.8
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declarar compatibilidad con HPOS (High-Performance Order Storage)
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Cargar el dominio de texto (aunque los textos están en el código, es buena práctica)
 */
function hpos_ardxoz_pagoqr_load_textdomain() {
	load_plugin_textdomain( 'hpos-ardxoz-pagoqr', false, basename( dirname( __FILE__ ) ) . '/languages' ); 
}
add_action( 'plugins_loaded', 'hpos_ardxoz_pagoqr_load_textdomain' );

/**
 * Registrar la pasarela en WooCommerce
 */
add_filter( 'woocommerce_payment_gateways', 'hpos_ardxoz_pagoqr_add_gateway_class' );
function hpos_ardxoz_pagoqr_add_gateway_class( $gateways ) {
	$gateways[] = 'HPOS_ARDXOZ_WC_Gateway_QR';
	return $gateways;
}

/**
 * Registrar integración con WooCommerce Blocks Checkout
 */
add_action( 'woocommerce_blocks_loaded', function() {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once plugin_dir_path( __FILE__ ) . 'inc/class-blocks-integration.php';

	add_action( 'woocommerce_blocks_payment_method_type_registration', function( $registry ) {
		$registry->register( new HPOS_ARDXOZ_PagoQR_Blocks_Integration() );
	} );
} );

/**
 * Inicializar la clase de la pasarela
 */
add_action( 'plugins_loaded', 'hpos_ardxoz_pagoqr_init_gateway_class' );
function hpos_ardxoz_pagoqr_init_gateway_class() {
 	
 	if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    // Cargar lógica de soporte
    require_once plugin_dir_path( __FILE__ ) . 'inc/functions.php';
 	
    class HPOS_ARDXOZ_WC_Gateway_QR extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'hpos_ardxoz_pagoqr';
            $this->icon = ''; // Se define en el checkout si hay icono personalizado
            $this->has_fields = false;
            $this->method_title = 'Pago QR HPOS';
            $this->method_description = 'Pasarela de pago QR optimizada para HPOS.';

            $this->supports = array( 'products' );

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->enabled = $this->get_option( 'enabled' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) ); 
        }

        /**
         * Campos de configuración en el admin
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Habilitar/Deshabilitar',
                    'label'       => 'Habilitar Pago por QR',
                    'type'        => 'checkbox',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Título',
                    'type'        => 'text',
                    'description' => 'Este es el título que el usuario verá durante el pago.',
                    'default'     => 'Pago por QR',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Descripción',
                    'type'        => 'textarea',
                    'description' => 'Instrucción que verá el usuario en el checkout.',
                    'default'     => 'Escanea el código QR, realiza el pago y adjunta tu comprobante para procesar el pedido.',
                    'desc_tip'    => true,
                ),
                'upload_icon' => array(
                    'title'       => 'Icono del método',
                    'type'        => 'hpos_ardxoz_pagoqr_icon', // Custom type handled in functions.php
                    'description' => 'Icono que se muestra junto al nombre del método en el checkout.',
                ),
                'preview_icon' => array(
                    'type'        => 'hidden',
                ),
                'number_telephone' => array(
                    'title'       => 'Número de contacto (opcional)',
                    'type'        => 'text',
                    'description' => 'Número de teléfono para contacto o billetera móvil.',
                    'default'     => '',
                ),
                'upload_qr' => array(
                    'title'       => 'Imagen del QR',
                    'type'        => 'hpos_ardxoz_pagoqr_image', // Custom type handled in functions.php
                    'description' => 'Sube la imagen del código QR que deben escanear los clientes.',
                ),
                'preview_qr' => array(
                    'type'        => 'hidden',
                ),
                'limit_amount' => array(
                    'title'       => 'Monto límite',
                    'type'        => 'text',
                    'description' => 'Monto máximo permitido para este método.',
                    'default'     => '',
                ),
                'message_limit_amount' => array(
                    'title'       => 'Mensaje de límite',
                    'type'        => 'text',
                    'description' => 'Mensaje que se muestra si se supera el límite.',
                    'default'     => 'Este método no permite pagos mayores al límite establecido.',
                )
            );
        }

        /**
         * Campos personalizados en el admin (Icono y QR)
         */
        public function generate_hpos_ardxoz_pagoqr_icon_html( $key, $data ) { return hpos_ardxoz_pagoqr_generate_admin_field_html( $this, $key, $data, 'icon' ); }
        public function generate_hpos_ardxoz_pagoqr_image_html( $key, $data ) { return hpos_ardxoz_pagoqr_generate_admin_field_html( $this, $key, $data, 'qr' ); }

        /**
         * Mostrar campos en el checkout
         */
        public function payment_fields() {
            if ( $this->description ) {
                echo wpautop( wp_kses_post( $this->description ) );
            }
            
            $preview_icon = $this->get_option( 'preview_icon' );
            if ( $preview_icon ) {
                echo '<img src="' . esc_url( $preview_icon ) . '" style="max-width: 50px; display: block; margin-top: 10px;" />';
            }
        }

        /**
         * Procesar el pago
         */
        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

            // Obtener la URL del comprobante de la sesión de WooCommerce
            $receipt_url = WC()->session->get( 'hpos_ardxoz_pagoqr_receipt_url' );

            if ( $receipt_url ) {
                $order->update_meta_data( '_hpos_ardxoz_pagoqr_receipt', esc_url_raw( $receipt_url ) );
                $order->save();
                
                // Limpiar la sesión
                WC()->session->set( 'hpos_ardxoz_pagoqr_receipt_url', null );
            }

            // Marcar como en espera
            $order->update_status( 'on-hold', 'Esperando validación del pago por QR.' );

            // Reducir stock
            wc_reduce_stock_levels( $order_id );

            // Vaciar carrito
            WC()->cart->empty_cart();

            // Retornar éxito y redirección
            return array(
                'result'    => 'success',
                'redirect'  => $this->get_return_url( $order )
            );
        }
    }
}
