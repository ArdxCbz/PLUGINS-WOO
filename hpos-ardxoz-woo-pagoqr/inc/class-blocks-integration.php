<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class HPOS_ARDXOZ_PagoQR_Blocks_Integration extends AbstractPaymentMethodType {

    protected $name = 'hpos_ardxoz_pagoqr';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_hpos_ardxoz_pagoqr_settings', array() );
    }

    public function is_active() {
        return ! empty( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_script_handles() {
        $asset_path = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/js/blocks.asset.php';
        $version    = '1.0.1';
        $deps       = array( 'wp-element', 'wp-html-entities', 'wc-blocks-registry', 'wc-settings' );

        if ( file_exists( $asset_path ) ) {
            $asset   = require $asset_path;
            $version = $asset['version'] ?? $version;
            $deps    = $asset['dependencies'] ?? $deps;
        }

        wp_register_script(
            'hpos-ardxoz-pagoqr-blocks',
            plugins_url( 'assets/js/blocks.js', dirname( __FILE__ ) ),
            $deps,
            $version,
            true
        );

        return array( 'hpos-ardxoz-pagoqr-blocks' );
    }

    public function get_payment_method_data() {
        $gateway = new HPOS_ARDXOZ_WC_Gateway_QR();
        return array(
            'title'       => $gateway->get_option( 'title', 'Pago por QR' ),
            'description' => $gateway->get_option( 'description' ),
            'icon'        => $gateway->get_option( 'preview_icon' ),
            'supports'    => array( 'products' ),
        );
    }
}
