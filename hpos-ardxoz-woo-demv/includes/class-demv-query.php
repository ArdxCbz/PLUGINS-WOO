<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Motor de consultas HPOS-safe para pedidos.
 *
 * Usa exclusivamente wc_get_orders() y $order->get_meta()
 * para máxima compatibilidad con HPOS y WooCommerce 10.7+.
 */
class HPOS_Ardxoz_Woo_DEMV_Query
{
    /**
     * Obtiene pedidos filtrados con paginación.
     *
     * @param array $filters
     * @return array {rows, total, pages, page}
     */
    public static function get_filtered_orders($filters)
    {
        $year     = intval($filters['year'] ?? wp_date('Y'));
        $per_page = intval($filters['per_page'] ?? 50);
        $page     = max(1, intval($filters['page'] ?? 1));

        // Filtros multi-valor: arrays vacíos = sin filtro
        $months         = self::normalize_int_array($filters['month'] ?? array(), 1, 12);
        $statuses       = self::normalize_string_array($filters['status'] ?? array());
        $shipping_arr   = self::normalize_string_array($filters['shipping_method'] ?? array());
        $billing_arr    = self::normalize_string_array($filters['billing_state'] ?? array());

        // — Rango de fechas —
        // Optimización: con un único mes acotamos la consulta a ese mes; con varios o ninguno usamos el año completo y filtramos después.
        if (count($months) === 1) {
            $m   = intval($months[0]);
            $mp  = str_pad($m, 2, '0', STR_PAD_LEFT);
            $ld  = date('t', mktime(0, 0, 0, $m, 1, $year));
            $date_range = "{$year}-{$mp}-01...{$year}-{$mp}-{$ld}";
        } else {
            $date_range = "{$year}-01-01...{$year}-12-31";
        }

        // — Args base para wc_get_orders —
        $args = array(
            'type'         => 'shop_order',
            'limit'        => -1,
            'return'       => 'ids',
            'date_created' => $date_range,
            'orderby'      => 'date',
            'order'        => 'DESC',
        );

        // Estado: wc_get_orders acepta array nativamente
        $args['status'] = !empty($statuses) ? $statuses : 'any';

        // Método de pago (nativo en wc_get_orders) — sigue siendo único
        $payment = $filters['payment_method'] ?? 'all';
        if ($payment && $payment !== 'all') {
            $args['payment_method'] = $payment;
        }

        // — Obtener IDs con filtros nativos —
        $order_ids = wc_get_orders($args);

        // — Post-filtros que requieren objeto WC_Order —
        $deposit_filter  = trim($filters['deposit'] ?? 'all');
        $deposit_search  = trim($filters['deposit_search'] ?? '');
        $no_deposit      = !empty($filters['no_deposit']);
        $sucursal_filter = strtoupper(trim($filters['sucursal'] ?? 'all'));
        $search          = trim($filters['search'] ?? '');
        $multi_month_filter = count($months) > 1; // se aplica solo si hay varios meses; el caso de 1 mes ya lo cubre el date_range

        // Soporte multi-término: separar búsqueda por espacios/comas
        $search_terms = array();
        if ($search !== '') {
            $search_terms = preg_split('/[\s,]+/', $search);
            $search_terms = array_values(array_filter(array_map('trim', $search_terms)));
        }

        $needs_post_filter = !empty($shipping_arr)
                          || !empty($billing_arr)
                          || $multi_month_filter
                          || ($deposit_filter && $deposit_filter !== 'all')
                          || $deposit_search !== ''
                          || $no_deposit
                          || ($sucursal_filter && $sucursal_filter !== 'ALL')
                          || !empty($search_terms);

        if ($needs_post_filter && !empty($order_ids)) {
            // Pre-filtro SQL por sucursal (evita N×M get_product() en el loop).
            if ($sucursal_filter && $sucursal_filter !== 'ALL') {
                $tax_map = self::get_orders_taxonomies($order_ids);
                $order_ids = array_values(array_filter($order_ids, function ($oid) use ($tax_map, $sucursal_filter) {
                    $info = $tax_map[(int) $oid] ?? null;
                    return $info && in_array($sucursal_filter, $info['sucursales'], true);
                }));
            }

            $filtered_ids = array();

            foreach ($order_ids as $oid) {
                $order = wc_get_order($oid);
                if (!$order) {
                    continue;
                }

                // Filtro multi-mes (cuando hay más de un mes seleccionado)
                if ($multi_month_filter) {
                    $created = $order->get_date_created();
                    if (!$created) {
                        continue;
                    }
                    $order_month = (int) $created->date('n');
                    if (!in_array($order_month, $months, true)) {
                        continue;
                    }
                }

                // Filtro: método de envío (cualquiera de los seleccionados)
                if (!empty($shipping_arr)) {
                    $methods = $order->get_shipping_methods();
                    $match = false;
                    foreach ($methods as $m) {
                        if (in_array($m->get_method_title(), $shipping_arr, true)) {
                            $match = true;
                            break;
                        }
                    }
                    if (!$match) {
                        continue;
                    }
                }

                // Filtro: departamento (billing_state) — cualquiera de los seleccionados
                if (!empty($billing_arr)) {
                    if (!in_array($order->get_billing_state(), $billing_arr, true)) {
                        continue;
                    }
                }

                // Filtros de depósito (exacto / substring / sin depósito)
                if (($deposit_filter && $deposit_filter !== 'all') || $deposit_search !== '' || $no_deposit) {
                    $dep_num = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_numero_deposito');

                    if ($deposit_filter && $deposit_filter !== 'all' && $dep_num !== $deposit_filter) {
                        continue;
                    }

                    if ($deposit_search !== '' && ($dep_num === '' || stripos((string) $dep_num, $deposit_search) === false)) {
                        continue;
                    }

                    if ($no_deposit && $dep_num !== '' && $dep_num !== null) {
                        continue;
                    }
                }

                // Búsqueda multi-término: cada término busca en order_id / order_number / shipping_postcode
                if (!empty($search_terms)) {
                    $oid_str   = strval($order->get_id());
                    $order_num = strval($order->get_order_number());
                    $postcode  = strtolower($order->get_shipping_postcode());

                    $match = false;
                    foreach ($search_terms as $term) {
                        $term_low = strtolower($term);
                        if ($oid_str === $term
                            || $order_num === $term
                            || ($postcode !== '' && strpos($postcode, $term_low) !== false)) {
                            $match = true;
                            break;
                        }
                    }

                    if (!$match) {
                        continue;
                    }
                }

                $filtered_ids[] = $oid;
            }

            $order_ids = $filtered_ids;
        }

        // Atajo: si el caller solo necesita los IDs filtrados (operaciones masivas),
        // retornamos antes de paginar/construir filas/calcular stats.
        if (!empty($filters['return_ids_only'])) {
            return array(
                'ids'   => array_values(array_map('intval', $order_ids)),
                'total' => count($order_ids),
            );
        }

        // — Paginación —
        $total       = count($order_ids);
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
        $offset      = ($page - 1) * $per_page;
        $paged_ids   = array_slice($order_ids, $offset, $per_page);

        // — Construir datos de fila —
        $rows = array();
        foreach ($paged_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) {
                continue;
            }
            $rows[] = self::build_row_data($order);
        }

        // — Estadísticas globales sobre TODOS los pedidos filtrados —
        $stats = self::compute_stats($order_ids);

        return array(
            'rows'  => $rows,
            'total' => $total,
            'pages' => $total_pages,
            'page'  => $page,
            'stats' => $stats,
        );
    }

    /**
     * Calcula totales acumulados sobre el conjunto completo de pedidos filtrados,
     * sin necesidad de cargar cada WC_Order. Usa SQL sobre wc_orders y wc_orders_meta.
     *
     * Retorna sumas de:
     *  - total_depositado       (meta _hpos_ardxoz_woo_monto_deposito | legacy IMPORTE_DEPOSITADO)
     *  - total_orders           (wc_orders.total_amount)
     *  - total_costo_envio      (meta _hpos_ardxoz_woo_costo_envio    | legacy costo_courier)
     *  - ibex_no_depositado     (7% retenido por IBEX en pedidos IBEX + Pago Contra Entrega)
     *  - diferencia_total       (Σ monto_depositado − esperado, donde esperado = Calculator::calcular() — para IBEX-COD ya descuenta el 7%. Excluye top-ups en curso.)
     *  - diferencia_por_metodo  (mismo cálculo agrupado por título del método de envío)
     *  - pending_topup_total    (Σ negativos de absorbers rezagados: con depósito previo + estado no terminal + faltante)
     *  - pending_topup_count    (nº de pedidos en estado de top-up pendiente)
     *
     * @param int[] $order_ids
     * @return array
     */
    public static function compute_stats($order_ids)
    {
        $empty = array(
            'count'                 => 0,
            'total_depositado'      => 0.0,
            'total_orders'          => 0.0,
            'total_costo_envio'     => 0.0,
            'ibex_no_depositado'    => 0.0,
            'ibex_count'            => 0,
            'diferencia_total'      => 0.0,
            'diferencia_por_metodo' => array(),
            'pending_topup_total'   => 0.0,
            'pending_topup_count'   => 0,
        );

        if (empty($order_ids)) {
            return $empty;
        }

        // Cache: invalidado por bump de hawd_stats_version (en hooks de pedidos).
        // Se incluye HAWD_VERSION para invalidar automáticamente al actualizar el
        // plugin si cambia el shape del payload (campos nuevos, etc.).
        $version   = (string) get_option('hawd_stats_version', '0');
        $plugin_v  = defined('HAWD_VERSION') ? (string) HAWD_VERSION : '';
        $cache_key = 'hawd_stats_' . md5($plugin_v . '|' . $version . '|' . implode(',', array_map('intval', $order_ids)));
        $cached    = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $orders_table = $wpdb->prefix . 'wc_orders';
        $meta_table   = $wpdb->prefix . 'wc_orders_meta';
        $items_table  = $wpdb->prefix . 'woocommerce_order_items';

        $total_orders            = 0.0;
        $total_depositado        = 0.0;
        $total_costo_envio       = 0.0;
        $total_costo_envio_ibex  = 0.0;
        $diff_por_metodo         = array(); // metodo => ['cnt' => n, 'diff' => float]
        $pending_topup_total     = 0.0;
        $pending_topup_count     = 0;

        // Estados terminales: el pedido ya no admite más depósitos.
        $terminal_statuses = array('wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed');

        // Procesa en lotes para evitar IN(...) excesivamente grandes
        $chunks = array_chunk(array_map('intval', $order_ids), 1000);

        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));

            // 1. Total + total por pedido + payment_method_title + status
            //    (necesarios para distinguir IBEX-COD y top-ups en curso).
            $rows_o = $wpdb->get_results($wpdb->prepare(
                "SELECT id, total_amount, payment_method_title, status
                 FROM {$orders_table}
                 WHERE id IN ($placeholders)",
                $chunk
            ));
            $total_per_order   = array();
            $payment_per_order = array();
            $status_per_order  = array();
            foreach ($rows_o as $r) {
                $oid = (int) $r->id;
                $amt = (float) $r->total_amount;
                $total_orders += $amt;
                $total_per_order[$oid]   = $amt;
                $payment_per_order[$oid] = (string) $r->payment_method_title;
                $status_per_order[$oid]  = (string) $r->status;
            }

            // 2. Monto depositado (HPOS preferente, fallback legacy)
            $rows_d = $wpdb->get_results($wpdb->prepare(
                "SELECT order_id,
                        MAX(CASE WHEN meta_key = '_hpos_ardxoz_woo_monto_deposito' THEN meta_value END) AS hpos,
                        MAX(CASE WHEN meta_key = 'IMPORTE_DEPOSITADO'              THEN meta_value END) AS legacy
                 FROM {$meta_table}
                 WHERE order_id IN ($placeholders)
                   AND meta_key IN ('_hpos_ardxoz_woo_monto_deposito', 'IMPORTE_DEPOSITADO')
                 GROUP BY order_id",
                $chunk
            ));
            $monto_per_order = array();
            foreach ($rows_d as $r) {
                $val = ($r->hpos !== null && $r->hpos !== '') ? $r->hpos : $r->legacy;
                if ($val !== null && $val !== '') {
                    $m = (float) $val;
                    $total_depositado += $m;
                    if ($m > 0) {
                        $monto_per_order[(int) $r->order_id] = $m;
                    }
                }
            }

            // 3. Métodos de envío del chunk: primer método por pedido + ibex_set
            $rows_s = $wpdb->get_results($wpdb->prepare(
                "SELECT order_id, order_item_name
                 FROM {$items_table}
                 WHERE order_item_type = 'shipping'
                   AND order_id IN ($placeholders)",
                $chunk
            ));
            $shipping_per_order = array();
            $ibex_set           = array();
            foreach ($rows_s as $r) {
                $oid  = (int) $r->order_id;
                $name = (string) $r->order_item_name;
                if (!isset($shipping_per_order[$oid])) {
                    $shipping_per_order[$oid] = $name;
                }
                if ($name === HPOS_Ardxoz_Woo_DEMV_Calculator::SHIPPING_IBEX) {
                    $ibex_set[$oid] = true;
                }
            }

            // 4. Costo de envío (HPOS preferente, fallback legacy)
            $rows_c = $wpdb->get_results($wpdb->prepare(
                "SELECT order_id,
                        MAX(CASE WHEN meta_key = '_hpos_ardxoz_woo_costo_envio' THEN meta_value END) AS hpos,
                        MAX(CASE WHEN meta_key = 'costo_courier'                THEN meta_value END) AS legacy
                 FROM {$meta_table}
                 WHERE order_id IN ($placeholders)
                   AND meta_key IN ('_hpos_ardxoz_woo_costo_envio', 'costo_courier')
                 GROUP BY order_id",
                $chunk
            ));
            foreach ($rows_c as $r) {
                $val = ($r->hpos !== null && $r->hpos !== '') ? $r->hpos : $r->legacy;
                if ($val !== null && $val !== '') {
                    $costo = (float) $val;
                    $total_costo_envio += $costo;
                    if (isset($ibex_set[(int) $r->order_id])) {
                        $total_costo_envio_ibex += $costo;
                    }
                }
            }

            // 5. Diferencia por método (solo pedidos con depósito registrado).
            //    diff = monto_depositado − esperado, donde esperado descuenta el 7%
            //    en IBEX+COD (mismo cálculo que Calculator::calcular). Así la
            //    retención IBEX deja de aparecer como diferencia falsa.
            //
            //    Los absorbers rezagados (estado no terminal + faltante) NO entran
            //    al breakdown — se reportan aparte como pending_topup_total.
            foreach ($monto_per_order as $oid => $monto) {
                $total = $total_per_order[$oid] ?? 0.0;
                if ($total <= 0) continue;
                $metodo  = $shipping_per_order[$oid] ?? '(Sin envío)';
                $payment = $payment_per_order[$oid] ?? '';
                $status  = $status_per_order[$oid]  ?? '';

                $expected = HPOS_Ardxoz_Woo_DEMV_Calculator::is_ibex_cod($metodo, $payment)
                    ? round($total * (1 - HPOS_Ardxoz_Woo_DEMV_Calculator::FEE_IBEX_COD), 2)
                    : round($total, 2);

                $delta = round($monto - $expected, 2);

                // Top-up en curso: faltante + pedido aún abierto.
                $is_topup_pending = ($delta < -0.01) && !in_array($status, $terminal_statuses, true);

                if ($is_topup_pending) {
                    $pending_topup_total += $delta;
                    $pending_topup_count++;
                    continue;
                }

                if (!isset($diff_por_metodo[$metodo])) {
                    $diff_por_metodo[$metodo] = array('cnt' => 0, 'diff' => 0.0);
                }
                $diff_por_metodo[$metodo]['cnt']++;
                $diff_por_metodo[$metodo]['diff'] += $delta;
            }
        }

        // Aplanar diferencia por método y total
        $diferencia_total      = 0.0;
        $diferencia_por_metodo = array();
        foreach ($diff_por_metodo as $metodo => $d) {
            $diff_round = round($d['diff'], 2);
            $diferencia_total += $diff_round;
            $diferencia_por_metodo[] = array(
                'metodo' => $metodo,
                'count'  => (int) $d['cnt'],
                'diff'   => $diff_round,
            );
        }
        usort($diferencia_por_metodo, function ($a, $b) {
            return strcmp($a['metodo'], $b['metodo']);
        });

        // Retención 7% IBEX+COD — fuente única de verdad en Calculator
        // (en vez de replicar aquí la regla de cálculo).
        $ibex_retention     = HPOS_Ardxoz_Woo_DEMV_Calculator::sum_ibex_cod_retention($order_ids);
        $ibex_no_depositado = (float) $ibex_retention['retained'];
        $ibex_count         = (int) $ibex_retention['count'];

        $stats = array(
            'count'                  => count($order_ids),
            'total_depositado'       => round($total_depositado, 2),
            'total_orders'           => round($total_orders, 2),
            'total_costo_envio'      => round($total_costo_envio, 2),
            'total_costo_envio_ibex' => round($total_costo_envio_ibex, 2),
            'ibex_no_depositado'     => round($ibex_no_depositado, 2),
            'ibex_count'             => $ibex_count,
            'diferencia_total'       => round($diferencia_total, 2),
            'diferencia_por_metodo'  => $diferencia_por_metodo,
            'pending_topup_total'    => round($pending_topup_total, 2),
            'pending_topup_count'    => $pending_topup_count,
        );

        set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);
        return $stats;
    }

    /**
     * Construye los datos de una fila para un pedido.
     */
    public static function build_row_data($order)
    {
        // Fecha
        $date_created = $order->get_date_created();
        $order_date = $date_created ? $date_created->date('d/m/Y') : '';

        // Usuario
        $user_id = $order->get_user_id();
        $user_login = '';
        if ($user_id) {
            $user = get_userdata($user_id);
            $user_login = $user ? $user->user_login : "#{$user_id}";
        }

        // Estado
        $status_key   = $order->get_status();
        $status_label = wc_get_order_status_name($status_key);

        // Departamento (billing_state → nombre completo)
        $billing_state = $order->get_billing_state();
        $billing_state_full = $billing_state;
        if ($billing_state) {
            $country = $order->get_billing_country() ?: 'BO';
            $states = WC()->countries->get_states($country);
            if ($states && isset($states[$billing_state])) {
                $billing_state_full = $states[$billing_state];
            }
        }

        // Método de envío (primer método)
        $shipping_method_title = '';
        foreach ($order->get_shipping_methods() as $method) {
            $shipping_method_title = $method->get_method_title();
            break;
        }

        // Metas con resolución HPOS/legacy
        $costo_envio      = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_costo_envio');
        $fecha_deposito   = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_fecha_deposito');
        $numero_deposito  = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_numero_deposito');
        $monto_deposito   = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_monto_deposito');
        $fecha_retorno    = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_fecha_retorno');
        $fecha_pago_envio = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_fecha_pago_envio');
        $checkbox_arqueo  = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_checkbox_arqueo');

        $has_deposit     = ($monto_deposito !== '' && floatval($monto_deposito) > 0);
        $has_pago_envio  = ($fecha_pago_envio !== '' && $fecha_pago_envio !== null);
        $has_arqueo_ok   = ((string) $checkbox_arqueo === '1');

        // Para top-ups (absorber rezagado), `importe_calculado` refleja el
        // faltante real a depositar ahora, no el calcular() completo. Esto
        // mantiene la modal y los sumarios coherentes con el plan del backend.
        $topup_info        = HPOS_Ardxoz_Woo_DEMV_Calculator::get_topup_info($order);
        $is_top_up         = (bool) $topup_info;
        $importe_calculado = HPOS_Ardxoz_Woo_DEMV_Calculator::pending_amount($order);

        return array(
            'id'                     => $order->get_id(),
            'date'                   => $order_date,
            'user_login'             => $user_login,
            'order_number'           => $order->get_order_number(),
            'postcode'               => $order->get_shipping_postcode(),
            'status'                 => $status_key,
            'status_label'           => $status_label,
            'payment_method_title'   => $order->get_payment_method_title(),
            'billing_state_full'     => $billing_state_full,
            'shipping_method_title'  => $shipping_method_title,
            'costo_envio'            => $costo_envio,
            'fecha_deposito'         => $fecha_deposito,
            'numero_deposito'        => $numero_deposito,
            'order_total'            => (float) $order->get_total(),
            'monto_deposito'         => $monto_deposito,
            'fecha_retorno'          => $fecha_retorno,
            'fecha_pago_envio'       => $fecha_pago_envio,
            'checkbox_arqueo'        => $checkbox_arqueo,
            'has_deposit'            => $has_deposit,
            'has_pago_envio'         => $has_pago_envio,
            'has_arqueo_ok'          => $has_arqueo_ok,
            'importe_calculado'      => $importe_calculado,
            'is_top_up'              => $is_top_up,
            'prior_monto_deposito'   => $is_top_up ? $topup_info['prior_monto'] : 0.0,
            'prior_numero_deposito'  => $is_top_up ? $topup_info['prior_num']   : '',
            'monto_efectivo'         => HPOS_Ardxoz_Woo_DEMV_Calculator::cash_amount($order),
            'has_cash'               => HPOS_Ardxoz_Woo_DEMV_Calculator::has_cash_payment($order),
            'edit_url'               => $order->get_edit_order_url(),
        );
    }

    /**
     * Métodos de envío usados en los últimos 6 meses.
     */
    public static function get_shipping_methods()
    {
        $cached = get_transient('hawd_shipping_methods');
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $six_months_ago = date('Y-m-d', strtotime('-6 months'));

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT oi.order_item_name
             FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id
             WHERE oi.order_item_type = 'shipping'
               AND oi.order_item_name != ''
               AND o.type = 'shop_order'
               AND o.date_created_gmt >= %s
             ORDER BY oi.order_item_name",
            $six_months_ago
        ));

        set_transient('hawd_shipping_methods', $results, 5 * MINUTE_IN_SECONDS);
        return $results;
    }

    /**
     * Métodos de pago usados en los últimos 6 meses.
     * Unifica por título (payment_method_title).
     */
    public static function get_payment_methods()
    {
        $cached = get_transient('hawd_payment_methods');
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_orders';
        $six_months_ago = date('Y-m-d', strtotime('-6 months'));

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return array();
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT MIN(payment_method) AS payment_method,
                    payment_method_title
             FROM {$table}
             WHERE type = 'shop_order'
               AND payment_method != ''
               AND payment_method_title != ''
               AND date_created_gmt >= %s
             GROUP BY payment_method_title
             ORDER BY payment_method_title",
            $six_months_ago
        ));

        set_transient('hawd_payment_methods', $results, 5 * MINUTE_IN_SECONDS);
        return $results;
    }

    /**
     * Departamentos (billing_state) usados en pedidos.
     * Retorna array de objetos {code, name}.
     */
    public static function get_billing_states()
    {
        $cached = get_transient('hawd_billing_states');
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_order_addresses';

        $codes = $wpdb->get_col(
            "SELECT DISTINCT state
             FROM {$table}
             WHERE address_type = 'billing'
               AND state != ''
             ORDER BY state"
        );

        if (empty($codes)) {
            return array();
        }

        $bo_states = WC()->countries->get_states('BO');
        $result = array();

        foreach ($codes as $code) {
            $name = (is_array($bo_states) && isset($bo_states[$code])) ? $bo_states[$code] : $code;
            $result[] = (object) array('code' => $code, 'name' => $name);
        }

        usort($result, function ($a, $b) {
            return strcmp($a->name, $b->name);
        });

        set_transient('hawd_billing_states', $result, 5 * MINUTE_IN_SECONDS);
        return $result;
    }

    /**
     * Números de depósito únicos para un año/mes y departamento específico.
     * Consulta wc_orders_meta + wc_order_addresses (HPOS-safe).
     *
     * @param int    $year
     * @param int    $month
     * @param string $billing_state Código del departamento (ej: 'LP').
     * @return array Lista de números de depósito.
     */
    public static function get_deposit_numbers($year, $month, $billing_state = '')
    {
        global $wpdb;
        $meta_table   = $wpdb->prefix . 'wc_orders_meta';
        $orders_table = $wpdb->prefix . 'wc_orders';
        $addr_table   = $wpdb->prefix . 'wc_order_addresses';

        $mp = str_pad(intval($month), 2, '0', STR_PAD_LEFT);
        $ld = date('t', mktime(0, 0, 0, $month, 1, $year));
        $start = "{$year}-{$mp}-01 00:00:00";
        $end   = "{$year}-{$mp}-{$ld} 23:59:59";

        $sql = "SELECT DISTINCT m.meta_value
                FROM {$meta_table} m
                INNER JOIN {$orders_table} o ON o.id = m.order_id
                INNER JOIN {$addr_table} a ON a.order_id = o.id AND a.address_type = 'billing'
                WHERE o.type = 'shop_order'
                  AND o.date_created_gmt >= %s
                  AND o.date_created_gmt <= %s
                  AND m.meta_key IN ('_hpos_ardxoz_woo_numero_deposito', 'numero_de_BANCARIO')
                  AND m.meta_value IS NOT NULL
                  AND m.meta_value != ''
                  AND a.state = %s
                ORDER BY m.meta_value";

        return $wpdb->get_col($wpdb->prepare($sql, $start, $end, $billing_state));
    }

    /**
     * Devuelve las sucursales (en mayúsculas) presentes en los productos del pedido,
     * leyendo el atributo de producto `pa_sucursal`.
     *
     * Para conjuntos de pedidos preferir `get_orders_taxonomies()` que evita N×M queries.
     *
     * @param WC_Order $order
     * @return string[]
     */
    public static function get_order_sucursales($order)
    {
        $sucursales = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $suc = strtoupper(trim((string) $product->get_attribute('pa_sucursal')));
            if ($suc !== '') $sucursales[$suc] = true;
        }
        return array_keys($sucursales);
    }

    /**
     * Versión masiva de `get_order_sucursales` que también detecta la categoría
     * `tienda`. Resuelve en una sola tanda SQL en vez de N×M llamadas a
     * `wc_get_product` + `get_attribute`.
     *
     * Para variaciones, prioriza `wp_woocommerce_order_itemmeta.pa_sucursal`
     * (slug específico de la variación elegida por el cliente). Solo cae a las
     * term_relationships del producto cuando el item no tiene ese itemmeta —
     * típicamente productos simples sin variaciones.
     *
     * @param int[] $order_ids
     * @return array<int, array{sucursales: string[], has_tienda: bool}>
     */
    public static function get_orders_taxonomies($order_ids)
    {
        $result = array();
        if (empty($order_ids)) {
            return $result;
        }

        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $order_ids))));
        if (empty($ids)) {
            return $result;
        }

        $items_table = $wpdb->prefix . 'woocommerce_order_items';
        $itemmeta    = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $tr_table    = $wpdb->prefix . 'term_relationships';
        $tt_table    = $wpdb->prefix . 'term_taxonomy';
        $terms_table = $wpdb->prefix . 'terms';

        foreach ($ids as $oid) {
            $result[$oid] = array('sucursales' => array(), 'has_tienda' => false);
        }

        $chunks = array_chunk($ids, 1000);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));

            // 1) Items del chunk: order_id, product_id (padre) y pa_sucursal directa del item.
            //    LEFT JOIN porque productos simples no tienen pa_sucursal a nivel item.
            $items_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT oi.order_id,
                        CAST(pid.meta_value AS UNSIGNED) AS product_id,
                        psuc.meta_value AS suc_slug
                 FROM {$items_table} oi
                 INNER JOIN {$itemmeta} pid
                     ON pid.order_item_id = oi.order_item_id
                    AND pid.meta_key = '_product_id'
                 LEFT JOIN {$itemmeta} psuc
                     ON psuc.order_item_id = oi.order_item_id
                    AND psuc.meta_key = 'pa_sucursal'
                 WHERE oi.order_item_type = 'line_item'
                   AND oi.order_id IN ($placeholders)",
                $chunk
            ));

            $used_slugs = array();
            $pids_all   = array();
            foreach ($items_rows as $r) {
                $pid = (int) $r->product_id;
                if ($pid > 0) $pids_all[$pid] = true;
                $slug = trim((string) $r->suc_slug);
                if ($slug !== '') $used_slugs[$slug] = true;
            }

            // 2) Resolver slug del item → nombre del término (en mayúsculas) para
            //    que coincida con Config::SUCURSALES (ej: 'santa-cruz' → 'SANTA CRUZ').
            $slug_to_name = array();
            if (!empty($used_slugs)) {
                $slugs   = array_keys($used_slugs);
                $slug_ph = implode(',', array_fill(0, count($slugs), '%s'));
                $term_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT t.slug, t.name
                     FROM {$tt_table} tt
                     INNER JOIN {$terms_table} t ON t.term_id = tt.term_id
                     WHERE tt.taxonomy = 'pa_sucursal'
                       AND t.slug IN ($slug_ph)",
                    $slugs
                ));
                foreach ($term_rows as $r) {
                    $slug_to_name[(string) $r->slug] = strtoupper(trim((string) $r->name));
                }
            }

            // 3) Term relationships del producto padre: para los items SIN pa_sucursal
            //    directa (productos simples) y para detectar product_cat=tienda en TODOS.
            $pid_sucursales = array();
            $pid_tienda     = array();
            if (!empty($pids_all)) {
                $all_pids = array_keys($pids_all);
                $pid_ph   = implode(',', array_fill(0, count($all_pids), '%d'));
                $tax_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT tr.object_id AS product_id, tt.taxonomy, t.name, t.slug
                     FROM {$tr_table} tr
                     INNER JOIN {$tt_table} tt
                         ON tt.term_taxonomy_id = tr.term_taxonomy_id
                        AND tt.taxonomy IN ('pa_sucursal', 'product_cat')
                     INNER JOIN {$terms_table} t
                         ON t.term_id = tt.term_id
                     WHERE tr.object_id IN ($pid_ph)",
                    $all_pids
                ));
                foreach ($tax_rows as $r) {
                    $pid = (int) $r->product_id;
                    if ($r->taxonomy === 'pa_sucursal') {
                        $name = strtoupper(trim((string) $r->name));
                        if ($name !== '') {
                            $pid_sucursales[$pid][$name] = true;
                        }
                    } elseif ($r->taxonomy === 'product_cat' && $r->slug === 'tienda') {
                        $pid_tienda[$pid] = true;
                    }
                }
            }

            // 4) Combinar: por cada item, decidir su sucursal.
            //    Regla: si el item tiene pa_sucursal directa → esa (variación elegida).
            //           si no → todas las del producto padre (productos simples).
            //    'tienda' siempre se evalúa por producto padre (no es atributo de variación).
            foreach ($items_rows as $r) {
                $oid = (int) $r->order_id;
                if (!isset($result[$oid])) continue;
                $pid  = (int) $r->product_id;
                $slug = trim((string) $r->suc_slug);

                if ($pid > 0 && !empty($pid_tienda[$pid])) {
                    $result[$oid]['has_tienda'] = true;
                }

                if ($slug !== '') {
                    $name = $slug_to_name[$slug] ?? '';
                    if ($name === '') {
                        // Fallback: el slug no se resolvió (término eliminado, etc.).
                        // Normalizamos guiones → espacios para tolerar 'santa-cruz'.
                        $name = strtoupper(str_replace('-', ' ', $slug));
                    }
                    $result[$oid]['sucursales'][$name] = true;
                } elseif ($pid > 0 && !empty($pid_sucursales[$pid])) {
                    foreach (array_keys($pid_sucursales[$pid]) as $name) {
                        $result[$oid]['sucursales'][$name] = true;
                    }
                }
            }
        }

        foreach ($result as $oid => &$v) {
            $v['sucursales'] = array_keys($v['sucursales']);
        }
        unset($v);

        return $result;
    }

    /**
     * Normaliza un filtro multi-valor de strings (acepta string suelto o array).
     * Descarta vacíos y valores literales 'all'.
     */
    private static function normalize_string_array($input)
    {
        if (!is_array($input)) {
            $input = array($input);
        }
        $out = array();
        foreach ($input as $v) {
            $v = trim((string) $v);
            if ($v !== '' && $v !== 'all') {
                $out[] = $v;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Normaliza un filtro multi-valor de enteros con clamp [min,max].
     */
    private static function normalize_int_array($input, $min, $max)
    {
        if (!is_array($input)) {
            $input = array($input);
        }
        $out = array();
        foreach ($input as $v) {
            $n = intval($v);
            if ($n >= $min && $n <= $max) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }
}
