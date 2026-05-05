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

                // Filtro: sucursal (atributo de producto pa_sucursal)
                if ($sucursal_filter && $sucursal_filter !== 'ALL') {
                    if (!in_array($sucursal_filter, self::get_order_sucursales($order), true)) {
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
     *  - total_depositado  (meta _hpos_ardxoz_woo_monto_deposito  | legacy IMPORTE_DEPOSITADO)
     *  - total_orders      (wc_orders.total_amount)
     *  - total_costo_envio (meta _hpos_ardxoz_woo_costo_envio     | legacy costo_courier)
     *  - ibex_no_depositado(7% retenido por IBEX en pedidos IBEX + Pago Contra Entrega)
     *  - por_usuario       (suma de total_amount agrupado por customer_id, ordenado desc)
     *
     * @param int[] $order_ids
     * @return array
     */
    public static function compute_stats($order_ids)
    {
        $empty = array(
            'count'              => 0,
            'total_depositado'   => 0.0,
            'total_orders'       => 0.0,
            'total_costo_envio'  => 0.0,
            'ibex_no_depositado' => 0.0,
            'ibex_count'         => 0,
            'por_usuario'        => array(),
        );

        if (empty($order_ids)) {
            return $empty;
        }

        global $wpdb;
        $orders_table = $wpdb->prefix . 'wc_orders';
        $meta_table   = $wpdb->prefix . 'wc_orders_meta';
        $items_table  = $wpdb->prefix . 'woocommerce_order_items';

        $total_orders            = 0.0;
        $total_depositado        = 0.0;
        $total_costo_envio       = 0.0;
        $total_costo_envio_ibex  = 0.0;
        $ibex_no_depositado      = 0.0;
        $ibex_count              = 0;
        $user_totals             = array(); // customer_id => ['cnt'=>n,'total'=>x]

        // Procesa en lotes para evitar IN(...) excesivamente grandes
        $chunks = array_chunk(array_map('intval', $order_ids), 1000);

        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));

            // Total de pedidos + agrupado por usuario en una sola pasada
            $rows_o = $wpdb->get_results($wpdb->prepare(
                "SELECT customer_id, total_amount
                 FROM {$orders_table}
                 WHERE id IN ($placeholders)",
                $chunk
            ));
            foreach ($rows_o as $r) {
                $amt = (float) $r->total_amount;
                $total_orders += $amt;
                $cid = intval($r->customer_id);
                if (!isset($user_totals[$cid])) {
                    $user_totals[$cid] = array('cnt' => 0, 'total' => 0.0);
                }
                $user_totals[$cid]['cnt']   += 1;
                $user_totals[$cid]['total'] += $amt;
            }

            // Monto depositado (HPOS preferente, fallback legacy)
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
            foreach ($rows_d as $r) {
                $val = ($r->hpos !== null && $r->hpos !== '') ? $r->hpos : $r->legacy;
                if ($val !== null && $val !== '') {
                    $total_depositado += (float) $val;
                }
            }

            // IDs del chunk con envío IBEX (para splitear costo total vs costo IBEX)
            $ibex_ids_args = array_merge($chunk, array(HPOS_Ardxoz_Woo_DEMV_Calculator::SHIPPING_IBEX));
            $ibex_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT oi.order_id
                 FROM {$items_table} oi
                 WHERE oi.order_id IN ($placeholders)
                   AND oi.order_item_type = 'shipping'
                   AND oi.order_item_name = %s",
                $ibex_ids_args
            ));
            $ibex_set = array_flip(array_map('intval', $ibex_ids));

            // Costo de envío (HPOS preferente, fallback legacy)
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

            // 7% IBEX no depositado: pedidos con envío IBEX + pago "Pago Contra Entrega"
            // Replica la condición exacta del Calculator (FEE_IBEX_COD).
            $args_ibex = array_merge(
                $chunk,
                array(
                    HPOS_Ardxoz_Woo_DEMV_Calculator::PAYMENT_COD,
                    HPOS_Ardxoz_Woo_DEMV_Calculator::SHIPPING_IBEX,
                )
            );
            $rows_ibex = $wpdb->get_results($wpdb->prepare(
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
                $args_ibex
            ));
            foreach ($rows_ibex as $r) {
                $ibex_no_depositado += (float) $r->total_amount * HPOS_Ardxoz_Woo_DEMV_Calculator::FEE_IBEX_COD;
                $ibex_count++;
            }
        }

        // Resolver nombres de usuario y ordenar por total descendente
        uasort($user_totals, function ($a, $b) {
            if ($a['total'] === $b['total']) return 0;
            return ($a['total'] < $b['total']) ? 1 : -1;
        });

        $por_usuario = array();
        foreach ($user_totals as $uid => $data) {
            $uid = intval($uid);
            if ($uid > 0) {
                $user = get_userdata($uid);
                if ($user) {
                    $login = $user->user_login;
                    $name  = $user->display_name ?: $user->user_login;
                } else {
                    $login = "#{$uid}";
                    $name  = "#{$uid}";
                }
            } else {
                $login = '(invitado)';
                $name  = 'Invitado';
            }
            $por_usuario[] = array(
                'user_id'    => $uid,
                'user_login' => $login,
                'name'       => $name,
                'count'      => intval($data['cnt']),
                'total'      => round((float) $data['total'], 2),
            );
        }

        return array(
            'count'                  => count($order_ids),
            'total_depositado'       => round($total_depositado, 2),
            'total_orders'           => round($total_orders, 2),
            'total_costo_envio'      => round($total_costo_envio, 2),
            'total_costo_envio_ibex' => round($total_costo_envio_ibex, 2),
            'ibex_no_depositado'     => round($ibex_no_depositado, 2),
            'ibex_count'             => $ibex_count,
            'por_usuario'            => $por_usuario,
        );
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

        $has_deposit     = ($monto_deposito !== '' && floatval($monto_deposito) > 0);
        $has_pago_envio  = ($fecha_pago_envio !== '' && $fecha_pago_envio !== null);

        // Importe calculado (para modal)
        $importe_calculado = HPOS_Ardxoz_Woo_DEMV_Calculator::calcular($order);

        return array(
            'id'                    => $order->get_id(),
            'date'                  => $order_date,
            'user_login'            => $user_login,
            'order_number'          => $order->get_order_number(),
            'postcode'              => $order->get_shipping_postcode(),
            'status'                => $status_key,
            'status_label'          => $status_label,
            'payment_method_title'  => $order->get_payment_method_title(),
            'billing_state_full'    => $billing_state_full,
            'shipping_method_title' => $shipping_method_title,
            'costo_envio'           => $costo_envio,
            'fecha_deposito'        => $fecha_deposito,
            'numero_deposito'       => $numero_deposito,
            'order_total'           => (float) $order->get_total(),
            'monto_deposito'        => $monto_deposito,
            'fecha_retorno'         => $fecha_retorno,
            'fecha_pago_envio'      => $fecha_pago_envio,
            'has_deposit'           => $has_deposit,
            'has_pago_envio'        => $has_pago_envio,
            'importe_calculado'     => $importe_calculado,
            'edit_url'              => $order->get_edit_order_url(),
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
