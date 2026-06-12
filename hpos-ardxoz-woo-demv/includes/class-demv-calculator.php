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
     * Monto pagado en efectivo por el cliente. Ese dinero entra a la Caja
     * Efectivo del vendedor (`_hpos_ardxoz_woo_monto_efectivo`) y se concilia
     * por el flujo de Caja, NO por el depósito bancario. Es la **única fuente**
     * para descontarlo del importe a depositar al banco y evitar duplicar el
     * pago (mitad efectivo + mitad QR).
     *
     * @return float
     */
    public static function cash_amount($order)
    {
        return round((float) $order->get_meta('_hpos_ardxoz_woo_monto_efectivo'), 2);
    }

    /**
     * True si el pedido tiene un monto pagado en efectivo (> 0). Estos pedidos
     * NO deben completarse desde el flujo de depósito bancario: su cierre lo
     * decide la aprobación de Caja Efectivo (`Caja::handle_aprobar`, umbral
     * `monto_deposito >= total`).
     */
    public static function has_cash_payment($order)
    {
        return self::cash_amount($order) > 0.01;
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
        // Lado banco: el efectivo se concilia por Caja, NUNCA se deposita al banco.
        // El faltante a depositar es (calcular − efectivo) − lo ya depositado. Sin
        // esto, un pedido mitad efectivo/QR reentra como top-up y se vuelve a
        // depositar la parte de efectivo (doble pago).
        $calc_bank   = round(max(0.0, self::calcular($order) - self::cash_amount($order)), 2);
        $faltante    = round(max(0.0, $calc_bank - $prior_monto), 2);
        if ($faltante <= 0.01) {
            return null;
        }

        return array(
            'prior_num'   => $num,
            'prior_monto' => round($prior_monto, 2),
            'faltante'    => $faltante,
            'calc'        => $calc_bank,
        );
    }

    /**
     * Monto que aún se debe depositar AL BANCO para el pedido:
     *   - Top-up (absorber rezagado): faltante (ya del lado banco, con el efectivo
     *     descontado en get_topup_info).
     *   - Caso normal: (calcular − efectivo).
     *
     * El efectivo ya cobrado se concilia por Caja Efectivo, nunca al banco. Sirve
     * como single source para que la UI y los handlers concuerden en cuánto debe
     * entrar al modal de depósito.
     */
    public static function pending_amount($order)
    {
        $info = self::get_topup_info($order);
        if ($info) {
            return $info['faltante']; // ya es lado banco (efectivo descontado)
        }
        return round(max(0.0, self::calcular($order) - self::cash_amount($order)), 2);
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
     * Efectivo: el esperado por pedido descuenta el `monto_efectivo` (ya cobrado
     * y conciliado por Caja Efectivo). Además, todo pedido con efectivo > 0
     * queda con `complete = false` (su cierre lo decide la aprobación de Caja),
     * y el plan lo marca con `is_cash = true`.
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

        $importes   = array();
        $cash_flags = array();
        $esperado   = 0.0;
        foreach ($orders as $o) {
            $oid = (int) $o->get_id();
            if (array_key_exists($oid, $esperado_override)) {
                // El override (faltante de top-up) YA viene del lado banco, con el
                // efectivo descontado en get_topup_info: no se vuelve a restar.
                $imp = round(max(0.0, (float) $esperado_override[$oid]), 2);
            } else {
                // Caso normal: el efectivo ya cobrado se concilia por Caja, se
                // descuenta del importe a depositar al banco (no duplicar el pago).
                $imp = round(max(0.0, self::calcular($o) - self::cash_amount($o)), 2);
            }
            $importes[$oid]   = $imp;
            $cash_flags[$oid] = self::has_cash_payment($o);
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

            // Pedido con pago en efectivo: el depósito bancario solo cubre la
            // parte QR. NO se completa aquí; el cierre lo decide la aprobación
            // de Caja Efectivo (umbral monto_deposito >= total).
            $plan[$oid]['is_cash'] = ! empty($cash_flags[$oid]);
            if ($plan[$oid]['is_cash']) {
                $plan[$oid]['complete'] = false;
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
