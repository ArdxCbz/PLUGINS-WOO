<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Búsqueda simplificada en la pantalla nativa de pedidos WooCommerce.
 *
 * Extiende la búsqueda para encontrar pedidos por:
 * - Número de pedido (nativo en WC, pero reforzado aquí para postmeta legacy)
 * - Shipping postcode (guía)
 *
 * Compatible 100% con HPOS. Cero llamadas a get_post_meta() o wp_postmeta.
 */
class HPOS_Ardxoz_Woo_DEMV_Search
{
    public static function init()
    {
        // Hook directo en la query HPOS
        add_filter('woocommerce_orders_table_query_clauses', array(__CLASS__, 'modify_query_clauses'), 10, 2);
    }

    /**
     * Modifica las cláusulas de la query HPOS para incluir
     * búsqueda por shipping_postcode en la pantalla de pedidos.
     */
    public static function modify_query_clauses($clauses, $query_args)
    {
        if (!is_admin() || empty($_GET['s'])) {
            return $clauses;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'woocommerce_page_wc-orders') {
            return $clauses;
        }

        if (!current_user_can('manage_woocommerce')) {
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
        $orders_table    = $wpdb->prefix . 'wc_orders';

        $conditions = array();
        foreach ($terms as $term) {
            $exact = $wpdb->prepare('%s', $term);

            // Shipping postcode (guía) — búsqueda exacta
            $conditions[] = "{$orders_table}.id IN (
                SELECT order_id FROM {$addresses_table}
                WHERE address_type = 'shipping' AND postcode = {$exact}
            )";
        }

        if (!empty($conditions)) {
            $extra_where = '(' . implode(' OR ', $conditions) . ')';
            $extra_where = "({$extra_where} AND {$orders_table}.type = 'shop_order')";

            if (!empty($clauses['where'])) {
                $clauses['where'] = '(' . $clauses['where'] . ') OR ' . $extra_where;
            } else {
                $clauses['where'] = $extra_where;
            }
        }

        return $clauses;
    }
}
