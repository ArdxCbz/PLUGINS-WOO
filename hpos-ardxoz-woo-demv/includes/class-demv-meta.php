<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wrapper para resolución de metas HPOS/legacy.
 *
 * Delega a Meta_Resolver de hpos-ardxoz-woo-orders si está disponible.
 * Implementa fallback interno idéntico como respaldo.
 *
 * CERO llamadas a get_post_meta() o $wpdb->postmeta.
 * Usa exclusivamente $order->get_meta() (WooCommerce CRUD).
 */
class HPOS_Ardxoz_Woo_DEMV_Meta
{
    /**
     * Mapeo de keys HPOS a keys legacy (ACF).
     * Solo se usa en el fallback interno si Meta_Resolver no está disponible.
     */
    private static $legacy_map = array(
        '_hpos_ardxoz_woo_fecha_deposito'  => array('F_deposito_bancario'),
        '_hpos_ardxoz_woo_numero_deposito' => array('numero_de_BANCARIO'),
        '_hpos_ardxoz_woo_monto_deposito'  => array('IMPORTE_DEPOSITADO'),
        '_hpos_ardxoz_woo_fecha_retorno'   => array('fecha_de_retorno'),
        '_hpos_ardxoz_woo_costo_envio'     => array('costo_courier'),
        '_hpos_ardxoz_woo_numero_guia'     => array('numero_guia'),
    );

    /**
     * Obtiene un valor de meta del pedido con resolución HPOS → legacy.
     *
     * @param WC_Order $order    Objeto pedido.
     * @param string   $hpos_key Meta key HPOS.
     * @return string            Valor encontrado o cadena vacía.
     */
    public static function get($order, $hpos_key)
    {
        // Primario: Meta_Resolver del plugin hpos-ardxoz-woo-orders
        if (class_exists('HPOS\\Ardxoz\\Woo\\Orders\\Meta_Resolver')) {
            return \HPOS\Ardxoz\Woo\Orders\Meta_Resolver::get($order, $hpos_key);
        }

        // Fallback interno (mismo algoritmo que Meta_Resolver)
        $value = $order->get_meta($hpos_key, true);
        if ($value !== '' && $value !== null && $value !== false) {
            return $value;
        }

        if (isset(self::$legacy_map[$hpos_key])) {
            foreach (self::$legacy_map[$hpos_key] as $legacy_key) {
                $value = $order->get_meta($legacy_key, true);
                if ($value !== '' && $value !== null && $value !== false) {
                    return $value;
                }
            }
        }

        return '';
    }
}
