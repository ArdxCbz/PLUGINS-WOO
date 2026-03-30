<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Auto-rellena el shipping_postcode con el número de pedido
 * para métodos de envío CBS, LOCAL, SUECIA.
 */
class HPOS_Ardxoz_Woo_DEMV_Checkout
{
    public static function init()
    {
        add_action('woocommerce_checkout_order_created', array(__CLASS__, 'fill_postcode'), 10, 1);
    }

    /**
     * Hook compatible con HPOS: woocommerce_checkout_order_created
     * recibe el objeto $order directamente.
     */
    public static function fill_postcode($order)
    {
        $local_methods = array(
            HPOS_Ardxoz_Woo_DEMV_Calculator::SHIPPING_CBS,
            HPOS_Ardxoz_Woo_DEMV_Calculator::SHIPPING_LOCAL,
            HPOS_Ardxoz_Woo_DEMV_Calculator::SHIPPING_SUECIA,
        );

        foreach ($order->get_shipping_methods() as $method) {
            if (in_array($method->get_method_title(), $local_methods, true)) {
                $order->set_shipping_postcode($order->get_order_number());
                $order->save();
                break;
            }
        }
    }
}
