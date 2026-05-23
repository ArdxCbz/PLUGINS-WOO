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
        if (self::is_ibex_cod($shipping_title, $payment)) {
            return round($total * (1 - self::FEE_IBEX_COD), 2);
        }

        // Todos los demás casos: importe completo
        return round($total, 2);
    }

    /**
     * True si la combinación envío+pago aplica retención IBEX (7% no depositado).
     * Punto único de verdad para la regla — referenciado tanto por `calcular()`
     * como por la agregación SQL en `Query::compute_stats()`.
     */
    public static function is_ibex_cod($shipping_title, $payment_title)
    {
        return $shipping_title === self::SHIPPING_IBEX
            && $payment_title === self::PAYMENT_COD;
    }

    /**
     * Suma el monto retenido por IBEX (7%) para los pedidos dados.
     * Solo cuenta los pedidos con envío IBEX + pago Contra Entrega.
     *
     * Reemplaza la query SQL inline que vivía en `Query::compute_stats()`,
     * eliminando la duplicación de la regla de cálculo.
     *
     * @param int[] $order_ids
     * @return array{count: int, retained: float}
     */
    public static function sum_ibex_cod_retention($order_ids)
    {
        $out = array('count' => 0, 'retained' => 0.0);
        if (empty($order_ids)) {
            return $out;
        }

        global $wpdb;
        $orders_table = $wpdb->prefix . 'wc_orders';
        $items_table  = $wpdb->prefix . 'woocommerce_order_items';

        $ids    = array_values(array_filter(array_map('intval', $order_ids)));
        $chunks = array_chunk($ids, 1000);

        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $args = array_merge($chunk, array(self::PAYMENT_COD, self::SHIPPING_IBEX));

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT o.total_amount
                 FROM {$orders_table} o
                 WHERE o.id IN ($placeholders)
                   AND o.payment_method_title = %s
                   AND EXISTS (
                       SELECT 1 FROM {$items_table} oi
                       WHERE oi.order_id = o.id
                         AND oi.order_item_type = 'shipping'
                         AND oi.order_item_name = %s
                   )",
                $args
            ));

            foreach ($rows as $r) {
                $out['retained'] += (float) $r->total_amount * self::FEE_IBEX_COD;
                $out['count']++;
            }
        }

        $out['retained'] = round($out['retained'], 2);
        return $out;
    }

    /**
     * Información de top-up (depósito complementario) si el pedido es un
     * absorber-rezagado: tiene depósito HPOS registrado, está en un estado
     * no terminal y aún le falta dinero respecto al calculado.
     *
     * Devuelve null si el pedido NO es candidato a top-up (caso normal:
     * pedido nuevo sin depósito previo, o ya completed/cancelled/etc.).
     *
     * @return array{prior_num:string,prior_monto:float,faltante:float,calc:float}|null
     */
    public static function get_topup_info($order)
    {
        // Estados que cierran el ciclo del pedido: no se admite más depósito.
        $bloqueados = array('completed', 'cancelled', 'refunded', 'failed');
        if (in_array($order->get_status(), $bloqueados, true)) {
            return null;
        }

        // Solo top-up si ya hay metas HPOS (no aplica a pedidos legacy ACF —
        // esos siguen el flujo de "re-completado de pedidos legacy", regla 21).
        $num = (string) HPOS_Ardxoz_Woo_DEMV_Meta::get_hpos_only($order, '_hpos_ardxoz_woo_numero_deposito');
        if ($num === '') {
            return null;
        }

        $prior_monto = (float) HPOS_Ardxoz_Woo_DEMV_Meta::get_hpos_only($order, '_hpos_ardxoz_woo_monto_deposito');
        $calc        = self::calcular($order);
        $faltante    = round(max(0.0, $calc - $prior_monto), 2);
        if ($faltante <= 0.01) {
            return null;
        }

        return array(
            'prior_num'   => $num,
            'prior_monto' => round($prior_monto, 2),
            'faltante'    => $faltante,
            'calc'        => round($calc, 2),
        );
    }

    /**
     * Monto que aún se debe depositar para el pedido:
     *   - Top-up (absorber rezagado): faltante respecto al calculado.
     *   - Caso normal: calcular() completo.
     *
     * Sirve como single source para que la UI y los handlers concuerden
     * en cuánto debe entrar al modal de depósito.
     */
    public static function pending_amount($order)
    {
        $info = self::get_topup_info($order);
        return $info ? $info['faltante'] : self::calcular($order);
    }

    /**
     * Calcula el plan de distribución del monto realmente depositado entre
     * los pedidos seleccionados.
     *
     * Reglas (single source of truth para Ajax::complete_deposit y
     * Depositos_Express::ajax_complete):
     *
     *  - esperado por pedido = $esperado_override[$oid] si está definido, si no calcular($o).
     *  - esperado total = SUM(esperado por pedido)
     *  - diff = round(monto_real − esperado, 2)
     *  - |diff| ≤ 0.01: cada pedido recibe su esperado y queda completed.
     *  - diff < 0 (faltante): el pedido $absorber recibe (esperado + diff)
     *    y NO queda completado. El resto recibe su esperado y queda completed.
     *  - diff > 0 (excedente): el pedido $absorber recibe (esperado + diff)
     *    y queda completed. El resto también queda completed.
     *
     * Si solo hay un pedido y |diff| > 0.01, el absorber se asigna automáticamente.
     *
     * @param WC_Order[] $orders
     * @param float      $monto_real
     * @param int|null   $absorber_id
     * @param array<int,float> $esperado_override  Mapa order_id => esperado.
     *                                             Usado para top-ups donde
     *                                             el esperado es el faltante,
     *                                             no el calcular() completo.
     * @return array|WP_Error
     *   {
     *     plan:        [order_id => ['monto'=>X,'complete'=>bool,'is_absorber'=>bool,'esperado'=>X,'diff'=>X]],
     *     esperado:    float,
     *     diff:        float,
     *     monto_real:  float,
     *     absorber_id: int|null
     *   }
     */
    public static function plan_deposit_distribution($orders, $monto_real, $absorber_id = null, $esperado_override = array())
    {
        $orders = array_values(array_filter((array) $orders));
        if (empty($orders)) {
            return new \WP_Error('hawd_no_orders', 'No hay pedidos para distribuir.');
        }

        $importes = array();
        $esperado = 0.0;
        foreach ($orders as $o) {
            $oid = (int) $o->get_id();
            $imp = array_key_exists($oid, $esperado_override)
                ? (float) $esperado_override[$oid]
                : self::calcular($o);
            $importes[$oid] = $imp;
            $esperado += $imp;
        }
        $monto_real = (float) $monto_real;
        if ($monto_real < 0) {
            return new \WP_Error('hawd_monto_invalido', 'El monto depositado no puede ser negativo.');
        }

        $diff = round($monto_real - $esperado, 2);
        $has_diff = abs($diff) > 0.01;

        // Auto-asignación cuando solo hay 1 pedido en juego.
        if ($has_diff && count($orders) === 1) {
            $absorber_id = (int) $orders[0]->get_id();
        }

        if ($has_diff) {
            $absorber_id = (int) $absorber_id;
            if (!$absorber_id || !isset($importes[$absorber_id])) {
                return new \WP_Error(
                    'hawd_absorber_required',
                    'Selecciona la guía que absorberá la diferencia.'
                );
            }
        } else {
            $absorber_id = null;
        }

        $plan = array();
        foreach ($orders as $o) {
            $oid  = (int) $o->get_id();
            $base = (float) $importes[$oid];
            $is_absorber = ($absorber_id === $oid);

            if ($is_absorber && $diff < 0) {
                // Faltante: aplica reducido, NO completar
                $plan[$oid] = array(
                    'monto'       => round($base + $diff, 2),
                    'complete'    => false,
                    'is_absorber' => true,
                    'esperado'    => round($base, 2),
                    'diff'        => $diff,
                );
            } elseif ($is_absorber && $diff > 0) {
                // Excedente: aplica aumentado, completar
                $plan[$oid] = array(
                    'monto'       => round($base + $diff, 2),
                    'complete'    => true,
                    'is_absorber' => true,
                    'esperado'    => round($base, 2),
                    'diff'        => $diff,
                );
            } else {
                $plan[$oid] = array(
                    'monto'       => round($base, 2),
                    'complete'    => true,
                    'is_absorber' => false,
                    'esperado'    => round($base, 2),
                    'diff'        => 0.0,
                );
            }
        }

        return array(
            'plan'        => $plan,
            'esperado'    => round($esperado, 2),
            'diff'        => $diff,
            'monto_real'  => round($monto_real, 2),
            'absorber_id' => $absorber_id,
        );
    }
}
