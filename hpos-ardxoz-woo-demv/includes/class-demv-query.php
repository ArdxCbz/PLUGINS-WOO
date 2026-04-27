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
        $year  = intval($filters['year'] ?? wp_date('Y'));
        $month = $filters['month'] ?? 'all';
        $per_page = intval($filters['per_page'] ?? 50);
        $page     = max(1, intval($filters['page'] ?? 1));

        // — Rango de fechas —
        if ($month && $month !== 'all') {
            $m   = intval($month);
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

        // Estado
        $status = $filters['status'] ?? 'all';
        $args['status'] = ($status && $status !== 'all') ? $status : 'any';

        // Método de pago (nativo en wc_get_orders)
        $payment = $filters['payment_method'] ?? 'all';
        if ($payment && $payment !== 'all') {
            $args['payment_method'] = $payment;
        }

        // — Obtener IDs con filtros nativos —
        $order_ids = wc_get_orders($args);

        // — Post-filtros que requieren objeto WC_Order —
        $shipping_filter = $filters['shipping_method'] ?? 'all';
        $deposit_filter  = trim($filters['deposit'] ?? 'all');
        $deposit_search  = trim($filters['deposit_search'] ?? '');
        $no_deposit      = !empty($filters['no_deposit']);
        $billing_state_f = $filters['billing_state'] ?? 'all';
        $search          = trim($filters['search'] ?? '');

        // Soporte multi-término: separar búsqueda por espacios/comas
        $search_terms = array();
        if ($search !== '') {
            $search_terms = preg_split('/[\s,]+/', $search);
            $search_terms = array_values(array_filter(array_map('trim', $search_terms)));
        }

        $needs_post_filter = ($shipping_filter && $shipping_filter !== 'all')
                          || ($deposit_filter && $deposit_filter !== 'all')
                          || $deposit_search !== ''
                          || $no_deposit
                          || ($billing_state_f && $billing_state_f !== 'all')
                          || !empty($search_terms);

        if ($needs_post_filter && !empty($order_ids)) {
            $filtered_ids = array();

            foreach ($order_ids as $oid) {
                $order = wc_get_order($oid);
                if (!$order) {
                    continue;
                }

                // Filtro: método de envío
                if ($shipping_filter && $shipping_filter !== 'all') {
                    $methods = $order->get_shipping_methods();
                    $match = false;
                    foreach ($methods as $m) {
                        if ($m->get_method_title() === $shipping_filter) {
                            $match = true;
                            break;
                        }
                    }
                    if (!$match) {
                        continue;
                    }
                }

                // Filtro: departamento (billing_state)
                if ($billing_state_f && $billing_state_f !== 'all') {
                    if ($order->get_billing_state() !== $billing_state_f) {
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

        return array(
            'rows'  => $rows,
            'total' => $total,
            'pages' => $total_pages,
            'page'  => $page,
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

        $has_deposit = ($monto_deposito !== '' && floatval($monto_deposito) > 0);

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
            'has_deposit'           => $has_deposit,
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
}
