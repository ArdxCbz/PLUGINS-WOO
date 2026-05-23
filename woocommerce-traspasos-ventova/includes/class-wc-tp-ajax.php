<?php
// Evita acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para manejar las solicitudes AJAX del plugin.
 */
class WC_TP_Ajax
{

    /**
     * Inicializa los hooks de AJAX.
     */
    public static function init()
    {
        // Registra los manejadores AJAX
        add_action('wp_ajax_wc_tp_get_products', [__CLASS__, 'get_products']);
        add_action('wp_ajax_wc_tp_transfer', [__CLASS__, 'transfer_stock']); // Crear nuevo
        add_action('wp_ajax_wc_tp_update_status', [__CLASS__, 'update_status']); // Cambiar estado
        add_action('wp_ajax_wc_tp_get_transfer_details', [__CLASS__, 'get_transfer_details']);
        add_action('wp_ajax_wc_tp_edit_transfer', [__CLASS__, 'edit_transfer']); // Nuevo: Editar
        add_action('wp_ajax_wc_tp_search_products', [__CLASS__, 'search_products']); // Nuevo: Buscar para editar
    }

    /**
     * Obtiene variaciones por sucursal y categoría.
     */
    public static function get_products()
    {
        check_ajax_referer('wc_tp_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Sin permiso']);
        }

        $origen = sanitize_text_field($_POST['origen'] ?? '');
        $cat_id = intval($_POST['categoria'] ?? 0);

        $rows = self::_search_variations($origen, '', $cat_id);
        wp_send_json_success(['rows' => $rows]);
    }

    /**
     * Buscador de productos (variaciones) para el modal de edición o general.
     */
    public static function search_products()
    {
        check_ajax_referer('wc_tp_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Sin permiso']);
        }

        $origen = sanitize_text_field($_POST['origen'] ?? '');
        $term = sanitize_text_field($_POST['term'] ?? '');

        if (empty($origen) || empty($term)) {
            wp_send_json_error(['message' => 'Faltan parámetros']);
        }

        $rows = self::_search_variations($origen, $term);
        wp_send_json_success(['rows' => $rows]);
    }

    /**
     * Helper interno para buscar variaciones en una sucursal.
     */
    private static function _search_variations($sucursal_slug, $search_term = '', $cat_id = 0)
    {
        $args = [
            'post_type' => 'product_variation',
            'posts_per_page' => 50, // Limite para rendimiento
            'meta_query' => [
                [
                    'key' => 'attribute_pa_sucursal',
                    'value' => $sucursal_slug,
                    'compare' => '=',
                ],
            ],
        ];

        if ($cat_id) {
            $parents = get_posts([
                'post_type' => 'product',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'tax_query' => [['taxonomy' => 'product_cat', 'terms' => $cat_id]],
            ]);
            if (empty($parents))
                return [];
            $args['post_parent__in'] = $parents;
        }

        if ($search_term) {
            $args['s'] = $search_term;
        }

        $q = new WP_Query($args);
        $rows = [];
        while ($q->have_posts()) {
            $q->the_post();
            $var = wc_get_product(get_the_ID());
            $stock = $var->get_stock_quantity();
            // Mostrar incluso si no tiene stock (para saber que existe), pero no permitir seleccionar si stock <= 0 logicamente en JS
            if ($stock === null)
                $stock = 0;

            $rows[] = [
                'id' => $var->get_id(),
                'name' => $var->get_name(), // Nombre completo con atributos
                'stock' => $stock,
                'sku' => $var->get_sku(),
            ];
        }
        wp_reset_postdata();
        return $rows;
    }

    /**
     * Crear NUEVO traspaso.
     */
    public static function transfer_stock()
    {
        check_ajax_referer('wc_tp_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Sin permiso']);
        }

        $items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);
        if (!is_array($items)) $items = [];
        $origen = sanitize_text_field($_POST['origen'] ?? '');
        $destino = sanitize_text_field($_POST['destino'] ?? '');
        $guia = sanitize_text_field($_POST['guia'] ?? '');
        $estado = sanitize_text_field($_POST['estado'] ?? 'En Curso');
        $descripcion = isset($_POST['descripcion'])
            ? sanitize_textarea_field(wp_unslash($_POST['descripcion']))
            : '';

        if (empty($origen) || empty($destino))
            wp_send_json_error(['message' => 'Origen/Destino invalidos']);
        if ($origen === $destino)
            wp_send_json_error(['message' => 'Origen y destino iguales']);

        $has_items = !empty($items);
        $has_desc  = $descripcion !== '';

        if (!$has_items && !$has_desc) {
            wp_send_json_error(['message' => 'Agrega al menos un producto o una descripción']);
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            // Solo se valida stock y se mueve inventario si hay items.
            // Los traspasos solo-descripción (bienes) no tocan stock.
            if ($has_items) {
                self::_validate_stock($items, $origen);
                self::_validate_dest_variations($items, $destino);
                self::_apply_movement($items, $origen, $destino, $estado);
            }

            $completed = ($estado === 'Recibido') ? 1 : 0;

            // Guardar Historial
            $table = $wpdb->prefix . 'wc_tp_history';
            $wpdb->insert(
                $table,
                [
                    'date_created' => current_time('mysql'),
                    'user_id' => get_current_user_id(),
                    'origen' => $origen,
                    'destino' => $destino,
                    'items' => wp_json_encode($items),
                    'descripcion' => $has_desc ? $descripcion : null,
                    'guia' => $guia,
                    'estado' => $estado,
                    'completed' => $completed,
                ],
                ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
            );

            $wpdb->query('COMMIT');
            wp_send_json_success();

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Editar traspaso existente.
     */
    public static function edit_transfer()
    {
        check_ajax_referer('wc_tp_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Sin permiso']);
        }

        $id = intval($_POST['id'] ?? 0);
        $new_guia = sanitize_text_field($_POST['guia'] ?? '');
        $new_items_json = stripslashes($_POST['items'] ?? '[]'); // JSON String
        $new_items = json_decode($new_items_json, true);
        if (!is_array($new_items)) $new_items = [];
        $new_descripcion = isset($_POST['descripcion'])
            ? sanitize_textarea_field(wp_unslash($_POST['descripcion']))
            : '';

        if (!$id)
            wp_send_json_error(['message' => 'ID invalido']);

        global $wpdb;
        $table = $wpdb->prefix . 'wc_tp_history';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
        if (!$row)
            wp_send_json_error(['message' => 'No encontrado']);

        $old_items = json_decode($row['items'] ?? '[]', true) ?: [];
        $origen = $row['origen'];
        $destino = $row['destino'];
        $estado = $row['estado'];
        $is_desc_only = empty($old_items) && !empty($row['descripcion']);

        // Traspaso solo-descripción: no hay stock que mover, edición simple.
        if ($is_desc_only) {
            $wpdb->update(
                $table,
                ['guia' => $new_guia, 'descripcion' => $new_descripcion],
                ['id' => $id],
                ['%s', '%s'],
                ['%d']
            );
            wp_send_json_success(['message' => 'Traspaso actualizado']);
            return;
        }

        // Comparamos Items para ver si cambiaron
        // Una comparación simple de JSON string puede servir si el orden se mantiene, pero mejor decodificar
        $items_changed = (wp_json_encode($new_items) !== wp_json_encode($old_items)); // Aproximado

        // Si solo cambió la guía, update simple
        if (!$items_changed) {
            $wpdb->update($table, ['guia' => $new_guia], ['id' => $id]);
            wp_send_json_success(['message' => 'Guía actualizada']);
            return;
        }

        // Si cambiaron items: Transacción compleja
        $wpdb->query('START TRANSACTION');
        try {
            // Validar destino antes de tocar stock
            self::_validate_dest_variations($new_items, $destino);

            // 1. REVERTIR el movimiento anterior
            self::_revert_movement($old_items, $origen, $destino, $estado);

            // 2. Validar nuevo stock una vez que el origen recuperó el stock anterior
            self::_validate_stock($new_items, $origen);

            // 3. APLICAR nuevo movimiento
            self::_apply_movement($new_items, $origen, $destino, $estado);

            // 4. Actualizar DB
            $wpdb->update(
                $table,
                ['guia' => $new_guia, 'items' => wp_json_encode($new_items)],
                ['id' => $id]
            );

            $wpdb->query('COMMIT');
            wp_send_json_success(['message' => 'Traspaso actualizado correctamente']);

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }

    /**
     * Actualiza estado (En Curso <-> Recibido).
     */
    public static function update_status()
    {
        check_ajax_referer('wc_tp_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Sin permiso']);
        }
        $id = intval($_POST['id'] ?? 0);
        $new_estado = sanitize_text_field($_POST['estado'] ?? '');

        global $wpdb;
        $table = $wpdb->prefix . 'wc_tp_history';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
        if (!$row)
            wp_send_json_error(['message' => 'No encontrado']);

        $current_estado = $row['estado'];
        if ($current_estado === $new_estado)
            wp_send_json_success(); // Sin cambios

        $items = json_decode($row['items'], true);
        $origen = $row['origen'];
        $destino = $row['destino'];

        $wpdb->query('START TRANSACTION');
        try {
            if ($new_estado === 'Recibido' && $current_estado === 'En Curso') {
                // Completar: Sumar a destino
                foreach ($items as $it) {
                    self::_add_stock_to_dest($it['id'], $it['qty'], $destino);
                }
                $completed = 1;
            } elseif ($new_estado === 'En Curso' && $current_estado === 'Recibido') {
                // Revertir recepción: Restar de destino
                foreach ($items as $it) {
                    self::_remove_stock_from_dest($it['id'], $it['qty'], $destino);
                }
                $completed = 0;
            } else {
                throw new Exception('Transición de estado no soportada');
            }

            $wpdb->update($table, ['estado' => $new_estado, 'completed' => $completed], ['id' => $id]);
            $wpdb->query('COMMIT');
            wp_send_json_success();
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    // --- HELPERS ---

    private static function _validate_dest_variations($items, $destino_slug)
    {
        foreach ($items as $it) {
            $src = wc_get_product(intval($it['id']));
            if (!$src) {
                throw new Exception("Producto ID {$it['id']} no existe.");
            }
            $target = self::_find_variation_in_branch($src->get_parent_id(), $src, $destino_slug);
            if (!$target) {
                $destino_name = WC_TP_Config::get_sucursal_name($destino_slug);
                throw new Exception("No existe variación en destino «{$destino_name}» para: {$it['name']}. Créala primero en WooCommerce.");
            }
        }
    }

    private static function _validate_stock($items, $origen_slug)
    {
        foreach ($items as $it) {
            $product = wc_get_product(intval($it['id']));
            if (!$product)
                throw new Exception("Producto ID {$it['id']} no existe");

            // Verificar que pertenezca a la sucursal origen (opcional pero recomendable)
            // $sucursal = $product->get_attribute('pa_sucursal'); 

            $current_stock = $product->get_stock_quantity();
            if ($it['qty'] > $current_stock) {
                throw new Exception("Stock insuficiente para {$it['name']}. Disponible: $current_stock, Solicitado: {$it['qty']}");
            }
        }
    }

    private static function _apply_movement($items, $origen, $destino, $estado)
    {
        // 1. Restar de origen (Siempre se hace al crear/aplicar)
        foreach ($items as $it) {
            $product = wc_get_product(intval($it['id']));
            if ($product) {
                $new_stock = max(0, $product->get_stock_quantity() - intval($it['qty']));
                $product->set_stock_quantity($new_stock);
                $product->save();
            }
        }

        // 2. Si ya es recibido, sumar a destino
        if ($estado === 'Recibido') {
            foreach ($items as $it) {
                self::_add_stock_to_dest($it['id'], $it['qty'], $destino);
            }
        }
    }

    private static function _revert_movement($items, $origen, $destino, $estado)
    {
        // Operación inversa a _apply_movement

        // 1. Sumar a origen (Devolver lo que se quitó)
        foreach ($items as $it) {
            $product = wc_get_product(intval($it['id']));
            if ($product) {
                $new_stock = $product->get_stock_quantity() + intval($it['qty']);
                $product->set_stock_quantity($new_stock);
                $product->save();
            }
        }

        // 2. Si estaba recibido, restar de destino (Quitar lo que se entregó)
        if ($estado === 'Recibido') {
            foreach ($items as $it) {
                self::_remove_stock_from_dest($it['id'], $it['qty'], $destino);
            }
        }
    }

    private static function _add_stock_to_dest($source_var_id, $qty, $dest_slug)
    {
        $src = wc_get_product($source_var_id);
        if (!$src)
            return;

        // Buscar variación equivalente en destino
        // Lógica: Mismo padre, mismos atributos EXCEPTO sucursal = dest_slug
        $parent_id = $src->get_parent_id();
        $target_var = self::_find_variation_in_branch($parent_id, $src, $dest_slug);

        if (!$target_var) {
            throw new Exception("Variación destino no encontrada para: " . $src->get_name() . ". Créala primero en WooCommerce antes de traspasar.");
        }

        $target_var->set_stock_quantity($target_var->get_stock_quantity() + $qty);
        $target_var->save();
    }

    private static function _remove_stock_from_dest($source_var_id, $qty, $dest_slug)
    {
        $src = wc_get_product($source_var_id);
        if (!$src)
            return;

        $target_var = self::_find_variation_in_branch($src->get_parent_id(), $src, $dest_slug);
        if ($target_var) {
            $new = max(0, $target_var->get_stock_quantity() - $qty);
            $target_var->set_stock_quantity($new);
            $target_var->save();
        }
    }

    private static function _find_variation_in_branch($parent_id, $src_product, $branch_slug)
    {
        // Obtener todos los hijos del padre
        // Esta puede ser una operación pesada si hay muchos hijos. 
        // Mejor usar meta_query directa como en el código original.

        $src_attrs = $src_product->get_variation_attributes();
        $src_attrs['attribute_pa_sucursal'] = $branch_slug; // Buscamos ESTA sucursal

        $meta_query = [];
        foreach ($src_attrs as $k => $v) {
            $meta_query[] = [
                'key' => $k,
                'value' => $v,
            ];
        }

        $args = [
            'post_type' => 'product_variation',
            'post_parent' => $parent_id,
            'posts_per_page' => 1,
            'meta_query' => $meta_query
        ];

        $q = new WP_Query($args);
        if ($q->have_posts()) {
            return wc_get_product($q->posts[0]->ID);
        }
        return null;
    }

    public static function get_transfer_details()
    {
        check_ajax_referer('wc_tp_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Sin permiso']);
        }
        $id = intval($_POST['id'] ?? 0);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wc_tp_history WHERE id = %d", $id), ARRAY_A);

        if (!$row)
            wp_send_json_error(['message' => 'No encontrado']);

        $items = json_decode($row['items'] ?? '[]', true) ?: [];
        $descripcion = $row['descripcion'] ?? '';
        $is_desc = empty($items) && $descripcion !== '';
        $user = get_userdata($row['user_id']);

        wp_send_json_success([
            'id' => $row['id'],
            'guia' => $row['guia'],
            'origen' => $row['origen'], // Slug
            'origen_name' => WC_TP_Mappings::map_sucursal($row['origen']),
            'destino' => $row['destino'],
            'destino_name' => WC_TP_Mappings::map_sucursal($row['destino']),
            'estado' => $row['estado'],
            'user' => $user ? $user->user_login : '—',
            'items' => $items,
            'descripcion' => $descripcion,
            'tipo' => $is_desc ? 'descripcion' : 'productos',
        ]);
    }
}