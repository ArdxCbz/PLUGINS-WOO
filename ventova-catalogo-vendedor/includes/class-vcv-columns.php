<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Columna "Stock por Sucursal" en la lista de productos del admin.
 *
 * Migra los bloques B1 y B2 del tema hijo
 * (`woocommerce-product-columns.php:14` y `:24`), que arrastraban los mismos
 * dos defectos que la página de vendedoras:
 *
 *   1. Llamaban `get_available_variations()` por cada fila de la tabla — la
 *      llamada más cara de WooCommerce — con 20 productos por pantalla.
 *   2. Solo contemplaban `is_type('variable')`: cualquier producto simple
 *      mostraba "Sin stock en sucursales", tuviera existencias o no.
 *
 * Aquí las filas se precalculan de una sola vez, enganchando `the_posts` de la
 * consulta principal: la pantalla entera cuesta lo mismo que un producto.
 */
class VCV_Columns
{
    const COLUMN = 'vcv_stock_sucursal';

    /** Filas precalculadas de la pantalla actual, indexadas por ID. */
    private static $rows = [];

    /** Sucursal de quien mira, para resaltar su badge. */
    private static $mi_sucursal = null;

    public static function init()
    {
        add_filter('manage_edit-product_columns', [__CLASS__, 'add_column'], 20);
        add_action('manage_product_posts_custom_column', [__CLASS__, 'render_column'], 10, 2);
        add_filter('the_posts', [__CLASS__, 'prefetch'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    /** True si el usuario puede ver la columna. */
    private static function visible()
    {
        return VCV_Permisos::is_admin_user();
    }

    public static function add_column($columns)
    {
        if (!self::visible()) {
            return $columns;
        }

        $columns[self::COLUMN] = __('Stock por Sucursal', 'ventova-catalogo-vendedor');

        return $columns;
    }

    /**
     * Precalcula todas las filas de la pantalla en una sola tanda.
     *
     * `the_posts` corre para cualquier WP_Query, así que se acota a la consulta
     * principal de la lista de productos del admin.
     *
     * @param WP_Post[] $posts
     * @param WP_Query  $query
     * @return WP_Post[] Sin modificar.
     */
    public static function prefetch($posts, $query = null)
    {
        if (empty($posts) || !is_admin() || !self::visible()) {
            return $posts;
        }
        if (!$query instanceof WP_Query || !$query->is_main_query()) {
            return $posts;
        }

        $post_type = $query->get('post_type');
        if ($post_type !== 'product' && !(is_array($post_type) && in_array('product', $post_type, true))) {
            return $posts;
        }

        $ids = [];
        foreach ($posts as $post) {
            if (isset($post->ID) && $post->post_type === 'product') {
                $ids[] = (int) $post->ID;
            }
        }

        if (!empty($ids)) {
            self::$rows = VCV_Query::get_rows_for_ids($ids);
        }

        return $posts;
    }

    public static function render_column($column, $post_id)
    {
        if ($column !== self::COLUMN || !self::visible()) {
            return;
        }

        if (self::$mi_sucursal === null) {
            self::$mi_sucursal = VCV_Permisos::user_sucursal();
        }

        $post_id = (int) $post_id;

        // Normalmente la fila ya viene precalculada por prefetch(). El respaldo
        // cubre pantallas que no pasan por la consulta principal (por ejemplo,
        // el refresco de una fila tras un Quick Edit por AJAX).
        if (!isset(self::$rows[$post_id])) {
            $fila = VCV_Query::get_rows_for_ids([$post_id]);
            self::$rows[$post_id] = $fila[$post_id] ?? null;
        }

        $row = self::$rows[$post_id];

        if (!$row) {
            echo '<span class="vcv-muted">—</span>';
            return;
        }

        echo VCV_Catalogo::stock_html($row, self::$mi_sucursal);
    }

    /** Los badges usan las clases del CSS del catálogo. */
    public static function enqueue($hook)
    {
        if ($hook !== 'edit.php' || !self::visible()) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        wp_enqueue_style('vcv-catalogo', VCV_URL . 'assets/catalogo.css', ['dashicons'], VCV_VERSION);
    }
}
