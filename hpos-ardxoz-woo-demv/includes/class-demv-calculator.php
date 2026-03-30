<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cálculo del importe a depositar según método de envío y pago.
 */
class HPOS_Ardxoz_Woo_DEMV_Calculator
{
    const SHIPPING_IBEX      = 'IBEX';
    const SHIPPING_SUECIA    = 'SUECIA';
    const SHIPPING_CBS       = 'CBS';
    const SHIPPING_LOCAL     = 'LOCAL';
    const SHIPPING_ENCOMIENDA = 'ENCOMIENDA';

    const PAYMENT_COD = 'Pago Contra Entrega';
    const PAYMENT_QR  = 'Pago QR';

    const FEE_IBEX_COD = 0.07; // 7%

    /**
     * Calcula el importe a depositar para un pedido.
     *
     * @param WC_Order $order
     * @return float
     */
    public static function calcular($order)
    {
        $total = (float) $order->get_total();
        $payment = $order->get_payment_method_title();

        $shipping_title = '';
        foreach ($order->get_shipping_methods() as $method) {
            $shipping_title = $method->get_method_title();
            break;
        }

        // IBEX + Contra Entrega = descuento 7%
        if ($shipping_title === self::SHIPPING_IBEX && $payment === self::PAYMENT_COD) {
            return round($total * (1 - self::FEE_IBEX_COD), 2);
        }

        // Todos los demás casos: importe completo
        return round($total, 2);
    }
}
