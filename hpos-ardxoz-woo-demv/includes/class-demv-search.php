<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extiende la búsqueda HPOS para mostrar pedidos por:
 * - Nro. Guía (shipping_postcode + _hpos_ardxoz_woo_numero_guia + numero_guia)
 * - Nro. Depósito (_hpos_ardxoz_woo_numero_deposito / numero_de_BANCARIO)
 * - Teléfono (billing_company)
 *
 * Usa woocommerce_orders_table_query_clauses para inyectar WHERE directamente.
 */
class HPOS_Ardxoz_Woo_DEMV_Search
{
    public static function init()
    {
        // Hook directo en la query HPOS (funciona en todas las versiones con HPOS)
        add_filter('woocommerce_orders_table_query_clauses', array(__CLASS__, 'modify_query_clauses'), 10, 2);

        // Agregar opciones al dropdown nativo si el hook existe (WC 8.9+)
        add_filter('woocommerce_hpos_admin_search_filters', array(__CLASS__, 'add_search_filters'));
        add_filter('woocommerce_hpos_generate_where_for_search_filter', array(__CLASS__, 'generate_where'), 10, 4);

        // Meta keys para búsqueda general "All"
        add_filter('woocommerce_order_table_search_query_meta_keys', array(__CLASS__, 'add_meta_keys'));

        // Fallback legacy (CPT)
        add_filter('woocommerce_shop_order_search_results', array(__CLASS__, 'extend_search_legacy'), 10, 3);
    }

    /**
     * Modifica directamente las cláusulas de la query HPOS
     * cuando hay una búsqueda activa en la pantalla de pedidos.
     */
    public static function modify_query_clauses($clauses, $query_args)
    {
        // Solo actuar si hay búsqueda en la pantalla de pedidos
        if (!is_admin() || empty($_GET['s'])) {
            return $clauses;
        }

        // Si hay un filtro custom seleccionado, generate_where() se encarga
        $search_filter = isset($_GET['search-filter']) ? sanitize_text_field($_GET['search-filter']) : '';
        if (in_array($search_filter, array('guia', 'deposito', 'telefono'), true)) {
            return $clauses;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'woocommerce_page_wc-orders') {
            return $clauses;
        }

        if (!current_user_can('administrator') && !current_user_can('shop_manager')) {
            return $clauses;
        }

        global $wpdb;
        $search_term = sanitize_text_field($_GET['s']);
        $terms = preg_split('/\s+/', trim($search_term));
        $terms = array_filter(array_map('trim', $terms));

        if (empty($terms)) {
            return $clauses;
        }

        $addresses_table = $wpdb->prefix . 'wc_order_addresses';
        $meta_table = $wpdb->prefix . 'wc_orders_meta';
        $orders_table = $wpdb->prefix . 'wc_orders';

        // Construir condiciones OR para cada término
        $conditions = array();
        foreach ($terms as $term) {
            $exact = $wpdb->prepare('%s', $term);
            $like = $wpdb->prepare('%s', '%' . $wpdb->esc_like($term) . '%');

            // Guía: shipping postcode (exacto)
            $conditions[] = "{$orders_table}.id IN (
                SELECT order_id FROM {$addresses_table}
                WHERE address_type = 'shipping' AND postcode = {$exact}
            )";

            // Guía: meta HPOS (exacto)
            $conditions[] = "{$orders_table}.id IN (
                SELECT order_id FROM {$meta_table}
                WHERE meta_key = '_hpos_ardxoz_woo_numero_guia' AND meta_value = {$exact}
            )";

            // Guía: meta legacy en wc_orders_meta (exacto)
            $conditions[] = "{$orders_table}.id IN (
                SELECT order_id FROM {$meta_table}
                WHERE meta_key = 'numero_guia' AND meta_value = {$exact}
            )";

            // Guía: meta legacy en wp_postmeta (exacto)
            $conditions[] = "{$orders_table}.id IN (
                SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = 'numero_guia' AND meta_value = {$exact}
            )";

            // Depósito: HPOS (LIKE)
            $conditions[] = "{$orders_table}.id IN (
                SELECT order_id FROM {$meta_table}
                WHERE meta_key = '_hpos_ardxoz_woo_numero_deposito' AND meta_value LIKE {$like}
            )";

            // Depósito: legacy en wc_orders_meta (LIKE)
            $conditions[] = "{$orders_table}.id IN (
                SELECT order_id FROM {$meta_table}
                WHERE meta_key = 'numero_de_BANCARIO' AND meta_value LIKE {$like}
            )";

            // Depósito: legacy en wp_postmeta (LIKE)
            $conditions[] = "{$orders_table}.id IN (
                SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = 'numero_de_BANCARIO' AND meta_value LIKE {$like}
            )";

            // Teléfono: billing_company (LIKE)
            $conditions[] = "{$orders_table}.id IN (
                SELECT order_id FROM {$addresses_table}
                WHERE address_type = 'billing' AND company LIKE {$like}
            )";
        }

        if (!empty($conditions)) {
            $extra_where = '(' . implode(' OR ', $conditions) . ')';

            // Asegurar que solo matchea pedidos (no refunds/trash)
            $extra_where = "({$extra_where} AND {$orders_table}.type = 'shop_order')";

            // Agregar como OR a la cláusula WHERE existente
            if (!empty($clauses['where'])) {
                $clauses['where'] = '(' . $clauses['where'] . ') OR ' . $extra_where;
            } else {
                $clauses['where'] = $extra_where;
            }
        }

        return $clauses;
    }

    /**
     * Agrega opciones al dropdown nativo HPOS (WC 8.9+).
     */
    public static function add_search_filters($filters)
    {
        $filters['guia']     = __('Nro. Guía', 'woocommerce');
        $filters['deposito'] = __('Nro. Depósito', 'woocommerce');
        $filters['telefono'] = __('Teléfono', 'woocommerce');
        return $filters;
    }

    /**
     * WHERE para filtros custom del dropdown (WC 8.9+).
     */
    public static function generate_where($where, $search_filter, $search_term, $query_object)
    {
        global $wpdb;

        if (!in_array($search_filter, array('guia', 'deposito', 'telefono'), true)) {
            return $where;
        }

        $addresses_table = $wpdb->prefix . 'wc_order_addresses';
        $meta_table = $wpdb->prefix . 'wc_orders_meta';
        $orders_prefix = $wpdb->prefix . 'wc_orders';

        $terms = preg_split('/\s+/', trim($search_term));
        $terms = array_filter(array_map('trim', $terms));

        if (empty($terms)) {
            return $where;
        }

        if ($search_filter === 'guia') {
            $conditions = array();
            foreach ($terms as $term) {
                $conditions[] = $wpdb->prepare(
                    "({$orders_prefix}.id IN (
                        SELECT order_id FROM {$addresses_table}
                        WHERE address_type = 'shipping' AND postcode = %s
                    )
                    OR {$orders_prefix}.id IN (
                        SELECT order_id FROM {$meta_table}
                        WHERE meta_key = '_hpos_ardxoz_woo_numero_guia' AND meta_value = %s
                    )
                    OR {$orders_prefix}.id IN (
                        SELECT order_id FROM {$meta_table}
                        WHERE meta_key = 'numero_guia' AND meta_value = %s
                    )
                    OR {$orders_prefix}.id IN (
                        SELECT post_id FROM {$wpdb->postmeta}
                        WHERE meta_key = 'numero_guia' AND meta_value = %s
                    ))",
                    $term, $term, $term, $term
                );
            }
            $where = '(' . implode(' OR ', $conditions) . ')';

        } elseif ($search_filter === 'deposito') {
            $conditions = array();
            foreach ($terms as $term) {
                $like = '%' . $wpdb->esc_like($term) . '%';
                $conditions[] = $wpdb->prepare(
                    "({$orders_prefix}.id IN (
                        SELECT order_id FROM {$meta_table}
                        WHERE meta_key = '_hpos_ardxoz_woo_numero_deposito' AND meta_value LIKE %s
                    )
                    OR {$orders_prefix}.id IN (
                        SELECT order_id FROM {$meta_table}
                        WHERE meta_key = 'numero_de_BANCARIO' AND meta_value LIKE %s
                    )
                    OR {$orders_prefix}.id IN (
                        SELECT post_id FROM {$wpdb->postmeta}
                        WHERE meta_key = 'numero_de_BANCARIO' AND meta_value LIKE %s
                    ))",
                    $like, $like, $like
                );
            }
            $where = '(' . implode(' OR ', $conditions) . ')';

        } elseif ($search_filter === 'telefono') {
            $conditions = array();
            foreach ($terms as $term) {
                $like = '%' . $wpdb->esc_like($term) . '%';
                $conditions[] = $wpdb->prepare(
                    "{$orders_prefix}.id IN (
                        SELECT order_id FROM {$addresses_table}
                        WHERE address_type = 'billing' AND company LIKE %s
                    )",
                    $like
                );
            }
            $where = '(' . implode(' OR ', $conditions) . ')';
        }

        return $where;
    }

    /**
     * Meta keys para búsqueda general "All" de HPOS.
     */
    public static function add_meta_keys($meta_keys)
    {
        $meta_keys[] = '_hpos_ardxoz_woo_numero_guia';
        $meta_keys[] = '_hpos_ardxoz_woo_numero_deposito';
        $meta_keys[] = 'numero_guia';
        $meta_keys[] = 'numero_de_BANCARIO';
        return $meta_keys;
    }

    /**
     * Fallback legacy (CPT).
     */
    public static function extend_search_legacy($order_ids, $term, $search_fields)
    {
        if (empty($term)) {
            return $order_ids;
        }

        if (!current_user_can('administrator') && !current_user_can('shop_manager')) {
            return $order_ids;
        }

        global $wpdb;
        $term = sanitize_text_field($term);
        $terms = preg_split('/\s+/', trim($term));
        $terms = array_filter(array_map('trim', $terms));
        $extra_ids = array();

        foreach ($terms as $single) {
            // _shipping_postcode
            $results = wc_get_orders(array(
                'limit'      => -1,
                'return'     => 'ids',
                'meta_query' => array(array(
                    'key'   => '_shipping_postcode',
                    'value' => $single,
                )),
            ));
            if (!empty($results)) $extra_ids = array_merge($extra_ids, $results);

            // numero_guia (wc_get_orders + wp_postmeta)
            $results = wc_get_orders(array(
                'limit'      => -1,
                'return'     => 'ids',
                'meta_query' => array(array(
                    'key'   => 'numero_guia',
                    'value' => $single,
                )),
            ));
            if (!empty($results)) $extra_ids = array_merge($extra_ids, $results);

            $postmeta_guia = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = 'numero_guia' AND meta_value = %s",
                $single
            ));
            if (!empty($postmeta_guia)) $extra_ids = array_merge($extra_ids, $postmeta_guia);

            // numero_de_BANCARIO (wc_get_orders + wp_postmeta)
            $results = wc_get_orders(array(
                'limit'      => -1,
                'return'     => 'ids',
                'meta_query' => array(array(
                    'key'     => 'numero_de_BANCARIO',
                    'value'   => $single,
                    'compare' => 'LIKE',
                )),
            ));
            if (!empty($results)) $extra_ids = array_merge($extra_ids, $results);

            $like = '%' . $wpdb->esc_like($single) . '%';
            $postmeta_dep = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = 'numero_de_BANCARIO' AND meta_value LIKE %s",
                $like
            ));
            if (!empty($postmeta_dep)) $extra_ids = array_merge($extra_ids, $postmeta_dep);
        }

        return array_unique(array_merge($order_ids, $extra_ids));
    }
}
