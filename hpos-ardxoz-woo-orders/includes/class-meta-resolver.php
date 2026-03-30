<?php
namespace HPOS\Ardxoz\Woo\Orders;

defined('ABSPATH') || exit;

/**
 * Resuelve meta keys con fallback: HPOS nuevo → ACF legacy.
 * Durante la transición, los pedidos antiguos tienen datos en ACF,
 * los nuevos en _hpos_ardxoz_woo_*. Esta clase consulta ambos.
 */
class Meta_Resolver
{
    /**
     * Mapeo: key HPOS → key(s) ACF legacy.
     * Algunos ACF usan get_field (que busca por field name, no meta key),
     * pero en la DB el meta_key real puede ser diferente.
     * Incluimos ambos: el meta_key directo y el field name de ACF.
     */
    private static $legacy_map = array(
        '_hpos_ardxoz_woo_fecha_deposito'    => array('F_deposito_bancario'),
        '_hpos_ardxoz_woo_numero_deposito'   => array('numero_de_BANCARIO'),
        '_hpos_ardxoz_woo_monto_deposito'    => array('IMPORTE_DEPOSITADO'),
        '_hpos_ardxoz_woo_fecha_retorno'     => array('fecha_de_retorno'),
        '_hpos_ardxoz_woo_checkbox_retorno'  => array('retorno_checkbox'),
        '_hpos_ardxoz_woo_costo_retorno'     => array('costo_retorno'),
        '_hpos_ardxoz_woo_costo_envio'       => array('costo_courier'),
        '_hpos_ardxoz_woo_numero_guia'       => array('numero_guia'),
    );

    /**
     * Obtiene un valor de meta del pedido con fallback a legacy.
     *
     * @param \WC_Order $order    Objeto pedido.
     * @param string    $hpos_key Meta key HPOS (ej: _hpos_ardxoz_woo_fecha_deposito).
     * @return string             Valor encontrado o cadena vacía.
     */
    public static function get($order, $hpos_key)
    {
        // 1. Intentar con el key HPOS nuevo
        $value = $order->get_meta($hpos_key, true);
        if ($value !== '' && $value !== null && $value !== false) {
            return $value;
        }

        // 2. Fallback: buscar en keys ACF legacy
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

    /**
     * Devuelve el mapeo completo para referencia.
     */
    public static function get_legacy_map()
    {
        return self::$legacy_map;
    }
}
