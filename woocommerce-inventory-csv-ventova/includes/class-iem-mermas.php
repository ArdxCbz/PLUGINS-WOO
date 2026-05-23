<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CRUD de mermas / defectuosos.
 *
 *  - Tipos fijos (no se permite texto libre). Ver get_tipos().
 *  - Al registrar con decrement_wc=true se descuenta del stock de WC vía
 *    wc_update_product_stock(). Solo funciona si el producto gestiona stock;
 *    si no, retorna WP_Error y nada se persiste.
 *  - El flag decremented_wc se guarda en la fila para auditoría.
 */
class IEM_Mermas
{
    /** Mapa slug => etiqueta. Único punto de definición de tipos. */
    public static function get_tipos()
    {
        return [
            'defectuoso' => 'Defectuoso',
            'rotura'     => 'Rotura',
            'caducidad'  => 'Caducidad',
            'robo'       => 'Robo',
            'extravio'   => 'Extravío',
            'otro'       => 'Otro',
        ];
    }

    public static function is_valid_tipo($tipo)
    {
        return array_key_exists((string) $tipo, self::get_tipos());
    }

    /**
     * Registra una merma. Si decrement_wc, descuenta del stock de WC primero;
     * solo si esa operación tuvo éxito se persiste la fila.
     *
     * @return int|WP_Error id de la merma insertada.
     */
    public static function register(array $args)
    {
        $args = array_merge([
            'item_id'       => 0,
            'qty'           => 0,
            'tipo'          => '',
            'sucursal_slug' => '',
            'user_id'       => get_current_user_id(),
            'session_id'    => null,
            'decrement_wc'  => false,
        ], $args);

        if ((int) $args['item_id'] <= 0
            || (int) $args['qty'] <= 0
            || !self::is_valid_tipo($args['tipo'])
            || trim((string) $args['sucursal_slug']) === '') {
            return new WP_Error('iem_invalid', 'Datos inválidos para registrar merma.');
        }

        $product = wc_get_product((int) $args['item_id']);
        if (!$product) {
            return new WP_Error('iem_no_product', 'Producto no encontrado.');
        }

        // Rechazar productos variables (padres): el stock se gestiona en la
        // variación, no en el padre. Exigir el SKU/ID de la variación específica.
        if ($product->is_type('variable')) {
            return new WP_Error('iem_must_be_variation',
                'Este SKU corresponde a un producto variable. Usa el SKU de la variación específica (producto hijo).');
        }

        $is_variation = $product->is_type('variation');
        $parent_id    = $is_variation ? $product->get_parent_id() : $product->get_id();

        // Snapshot del costo unitario al momento del registro (v3.8+).
        // Lee meta del propio item; fallback al padre si la variación no lo tiene.
        $cost_snapshot = get_post_meta((int) $product->get_id(), '_alg_wc_cog_cost', true);
        if (($cost_snapshot === '' || $cost_snapshot === null) && $is_variation) {
            $cost_snapshot = get_post_meta((int) $parent_id, '_alg_wc_cog_cost', true);
        }
        $cost_snapshot = ($cost_snapshot === '' || $cost_snapshot === null)
            ? null
            : (float) $cost_snapshot;

        $decremented = 0;
        if ($args['decrement_wc']) {
            if (!$product->managing_stock()) {
                return new WP_Error('iem_no_stock_mgmt',
                    'El producto no gestiona stock; no se puede descontar de WooCommerce.');
            }
            // wc_update_product_stock con un WC_Product_Variation decrementa
            // el _stock de la variación (el "producto hijo"), no del padre.
            $new_qty = wc_update_product_stock($product, (int) $args['qty'], 'decrease');
            if ($new_qty === false || is_wp_error($new_qty)) {
                return new WP_Error('iem_decrement_failed',
                    'No se pudo descontar del stock de WooCommerce.');
            }
            $decremented = 1;
        }

        global $wpdb;
        $ok = $wpdb->insert(IEM_Schema::table('mermas'), [
            'item_id'          => (int) $args['item_id'],
            'parent_id'        => (int) $parent_id,
            'sku'              => (string) $product->get_sku(),
            'name'             => (string) $product->get_name(),
            'sucursal_slug'    => (string) $args['sucursal_slug'],
            'qty'              => (int) $args['qty'],
            'tipo'             => (string) $args['tipo'],
            'user_id'          => (int) $args['user_id'],
            'session_id'       => $args['session_id'] ? (int) $args['session_id'] : null,
            'decremented_wc'   => $decremented,
            'cost_at_register' => $cost_snapshot,
            'created_at'       => current_time('mysql'),
        ]);
        if (!$ok) {
            return new WP_Error('iem_db_insert', 'No se pudo guardar la merma.');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Retorna una merma al inventario.
     *
     * Reglas:
     *  - La merma debe existir y NO estar ya retornada (idempotencia).
     *  - Si la merma original decrementó WC stock (`decremented_wc=1`), aquí se
     *    hace un `wc_update_product_stock(..., 'increase')` simétrico para
     *    devolver la cantidad al stock vendible.
     *  - Si NO decrementó originalmente, no se toca el stock — solo se marca
     *    la fila como retornada (preserva la simetría con el registro original).
     *  - La fila NO se borra: queda con `returned_at` + `returned_by` para
     *    auditoría. El listado la muestra en estado "Retornada".
     *
     * @return true|WP_Error
     */
    public static function return_to_stock($id, $user_id = null)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return new WP_Error('iem_invalid', 'Merma inválida.');
        }
        global $wpdb;
        $t   = IEM_Schema::table('mermas');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id), ARRAY_A);
        if (!$row) {
            return new WP_Error('iem_not_found', 'Merma no encontrada.');
        }
        if (!empty($row['returned_at'])) {
            return new WP_Error('iem_already_returned', 'Esta merma ya fue retornada al inventario.');
        }

        // Espejo del registro original: si decrementó WC, ahora se incrementa.
        if ((int) $row['decremented_wc'] === 1) {
            $product = wc_get_product((int) $row['item_id']);
            if (!$product) {
                return new WP_Error('iem_no_product',
                    'No se encuentra el producto original; no se puede retornar el stock.');
            }
            if (!$product->managing_stock()) {
                return new WP_Error('iem_no_stock_mgmt',
                    'El producto ya no gestiona stock; no se puede retornar la cantidad.');
            }
            $new_qty = wc_update_product_stock($product, (int) $row['qty'], 'increase');
            if ($new_qty === false || is_wp_error($new_qty)) {
                return new WP_Error('iem_increase_failed',
                    'No se pudo incrementar el stock de WooCommerce.');
            }
        }

        $ok = $wpdb->update($t,
            [
                'returned_at' => current_time('mysql'),
                'returned_by' => (int) ($user_id ?: get_current_user_id()),
            ],
            ['id' => $id],
            ['%s', '%d'],
            ['%d']
        );
        if ($ok === false) {
            return new WP_Error('iem_db_update', 'No se pudo marcar la merma como retornada.');
        }
        return true;
    }

    /**
     * Lista mermas con filtros. Por defecto últimas 500.
     */
    public static function query(array $args = [])
    {
        global $wpdb;
        $t = IEM_Schema::table('mermas');
        $where  = ['1=1'];
        $params = [];
        if (!empty($args['sucursal_slug'])) { $where[] = 'sucursal_slug=%s';     $params[] = $args['sucursal_slug']; }
        if (!empty($args['tipo']))          { $where[] = 'tipo=%s';              $params[] = $args['tipo']; }
        if (!empty($args['from']))          { $where[] = 'created_at >= %s';     $params[] = $args['from']; }
        if (!empty($args['to']))            { $where[] = 'created_at <= %s';     $params[] = $args['to']; }
        if (!empty($args['session_id']))    { $where[] = 'session_id = %d';      $params[] = (int) $args['session_id']; }

        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 500;
        $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where)
             . " ORDER BY created_at DESC, id DESC LIMIT $limit";
        $sql = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Busca un producto por SKU exacto. Wrapper para el form de mermas.
     * @return int product_id (0 si no se encontró)
     */
    public static function resolve_by_sku($sku)
    {
        $sku = trim((string) $sku);
        if ($sku === '') return 0;
        $id = wc_get_product_id_by_sku($sku);
        return (int) $id;
    }

    /**
     * Resolución completa de un SKU para el form de mermas.
     * Aplica las MISMAS reglas que `register()`:
     *  - SKU no vacío.
     *  - Producto existe.
     *  - No es producto variable (padre): debe ser simple o variación.
     *
     * Útil para validación previa al submit (AJAX) y como single source of
     * truth de qué SKU es aceptable para merma.
     *
     * @param string $sku
     * @return array|WP_Error
     *   En éxito: ['item_id','parent_id','name','sku','type','is_variation',
     *              'managing_stock','current_stock'].
     */
    public static function resolve_for_merma($sku)
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return new WP_Error('iem_sku_empty', 'Ingresa un SKU.');
        }
        $id = self::resolve_by_sku($sku);
        if ($id <= 0) {
            return new WP_Error('iem_sku_not_found', 'No se encontró ningún producto con ese SKU.');
        }
        $p = wc_get_product($id);
        if (!$p) {
            return new WP_Error('iem_no_product', 'Producto no encontrado.');
        }
        if ($p->is_type('variable')) {
            return new WP_Error('iem_must_be_variation',
                'Este SKU corresponde a un producto variable. Usa el SKU de una variación específica (producto hijo).');
        }
        $is_variation = $p->is_type('variation');
        $qty          = $p->get_stock_quantity();
        $suc_slug     = IEM_Sucursales::resolve_product_sucursal_slug($p);
        $suc_map      = IEM_Sucursales::get_map();
        return [
            'item_id'                 => (int) $p->get_id(),
            'parent_id'               => $is_variation ? (int) $p->get_parent_id() : (int) $p->get_id(),
            'name'                    => (string) $p->get_name(),
            'sku'                     => (string) $p->get_sku(),
            'type'                    => (string) $p->get_type(),
            'is_variation'            => (bool) $is_variation,
            'managing_stock'          => (bool) $p->managing_stock(),
            'current_stock'           => $qty === null ? null : (int) $qty,
            'suggested_sucursal_slug' => $suc_slug,
            'suggested_sucursal_name' => $suc_slug !== '' ? ($suc_map[$suc_slug] ?? $suc_slug) : '',
        ];
    }
}
