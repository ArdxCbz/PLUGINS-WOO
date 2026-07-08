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
        $cat_id = intval($_POST['categoria'] ?? 0);

        if (empty($origen)) {
            wp_send_json_error(['message' => 'Selecciona la sucursal origen']);
        }

        $rows = self::_search_variations($origen, $term, $cat_id);
        wp_send_json_success(['rows' => $rows]);
    }

    /**
     * Busca variaciones de una sucursal por nombre (título del padre) o SKU.
     *
     * Alimenta el buscador Select2 del paso 2 y el del modal de edición. Va
     * directo a SQL para poder buscar por SKU (`_sku` en postmeta, que WP_Query
     * `s` no cubre) además del nombre, acotando a `attribute_pa_sucursal = origen`
     * para que solo aparezcan las variaciones válidas del traspaso. Reemplaza al
     * WP_Query con tope fijo de 50 y búsqueda solo por título.
     *
     * @param string $sucursal_slug Slug de la sucursal origen (obligatorio).
     * @param string $search_term   Texto a buscar en nombre del padre o SKU.
     * @param int    $cat_id        Categoría (product_cat) opcional para refinar.
     * @return array<int,array{id:int,name:string,sku:string,stock:int,text:string}>
     */
    private static function _search_variations($sucursal_slug, $search_term = '', $cat_id = 0)
    {
        global $wpdb;

        $sucursal_slug = trim((string) $sucursal_slug);
        if ($sucursal_slug === '') {
            return [];
        }

        $limit = 30;

        // La sucursal se filtra en el JOIN (primer %s); los demás placeholders
        // van en orden de aparición en el string para $wpdb->prepare posicional.
        $sql = "SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} suc
                    ON suc.post_id = p.ID AND suc.meta_key = 'attribute_pa_sucursal' AND suc.meta_value = %s
                INNER JOIN {$wpdb->posts} parent
                    ON parent.ID = p.post_parent
                LEFT JOIN {$wpdb->postmeta} sku
                    ON sku.post_id = p.ID AND sku.meta_key = '_sku'";
        $params = [$sucursal_slug];

        if ($cat_id > 0) {
            $sql .= " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = parent.ID
                      INNER JOIN {$wpdb->term_taxonomy} tt
                          ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'";
        }

        $sql .= " WHERE p.post_type = 'product_variation' AND p.post_status = 'publish'";

        if ($cat_id > 0) {
            $sql .= " AND tt.term_id = %d";
            $params[] = $cat_id;
        }

        if ($search_term !== '') {
            $like = '%' . $wpdb->esc_like($search_term) . '%';
            $sql .= " AND (parent.post_title LIKE %s OR sku.meta_value LIKE %s)";
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " ORDER BY parent.post_title ASC LIMIT %d";
        $params[] = $limit;

        $ids = $wpdb->get_col($wpdb->prepare($sql, $params));

        $rows = [];
        foreach ($ids as $vid) {
            $var = wc_get_product((int) $vid);
            if (!$var) {
                continue;
            }
            $stock = $var->get_stock_quantity();
            if ($stock === null) {
                $stock = 0;
            }
            $sku  = (string) $var->get_sku();
            $name = $var->get_name(); // Nombre completo con atributos (incluye sucursal/color)
            $text = ($sku !== '' ? "[{$sku}] " : '') . $name . ' · Stock: ' . (int) $stock;

            $rows[] = [
                'id'    => $var->get_id(),
                'name'  => $name,
                'sku'   => $sku,
                'stock' => (int) $stock,
                'text'  => $text,
            ];
        }
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
        // Solo se permiten los dos estados del flujo interno. Cualquier otro
        // valor dejaría el traspaso atascado (update_status solo transita
        // 'En Curso' <-> 'Recibido').
        if (!in_array($estado, ['En Curso', 'Recibido'], true)) {
            $estado = 'En Curso';
        }
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
            }

            $completed = ($estado === 'Recibido') ? 1 : 0;

            // Guardar Historial PRIMERO: el id de la fila es la referencia que
            // se persiste en el Kardex (ref_id), por eso se inserta antes de
            // mover stock. Si el movimiento falla, el ROLLBACK deshace también
            // este INSERT (todo en la misma transacción).
            $table = $wpdb->prefix . 'wc_tp_history';
            $inserted = $wpdb->insert(
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
            if ($inserted === false) {
                throw new Exception('No se pudo guardar el traspaso.');
            }
            $transfer_id = (int) $wpdb->insert_id;

            if ($has_items) {
                self::_apply_movement($items, $origen, $destino, $estado, $transfer_id);
            }

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
            self::_revert_movement($old_items, $origen, $destino, $estado, $id);

            // 2. Validar nuevo stock una vez que el origen recuperó el stock anterior
            self::_validate_stock($new_items, $origen);

            // 3. APLICAR nuevo movimiento
            self::_apply_movement($new_items, $origen, $destino, $estado, $id);

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

        $items = json_decode($row['items'] ?? '[]', true) ?: [];
        $origen = $row['origen'];
        $destino = $row['destino'];

        $wpdb->query('START TRANSACTION');
        try {
            if ($new_estado === 'Recibido' && $current_estado === 'En Curso') {
                // Completar: Sumar a destino
                foreach ($items as $it) {
                    self::_add_stock_to_dest($it['id'], $it['qty'], $destino, $id, $origen);
                }
                $completed = 1;
            } elseif ($new_estado === 'En Curso' && $current_estado === 'Recibido') {
                // Revertir recepción: Restar de destino
                foreach ($items as $it) {
                    self::_remove_stock_from_dest($it['id'], $it['qty'], $destino, $id, 'Reverso de recepción de traspaso');
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

    /**
     * Mueve el stock de UNA variación registrándolo en el Kardex de IEM
     * (`IEM_Kardex::record`) con referencia de traspaso, de modo que el
     * movimiento aparezca en el Kardex como "Traspaso" y no como `manual_wc`
     * anónimo. `record(update_wc=true)` cambia el stock de WC con guard interno
     * (no dispara el catch-all del kardex) e inserta la fila del ledger; todo
     * dentro de la transacción SQL abierta por el caller.
     *
     * Si el plugin de Inventario (IEM) NO está activo, cae al movimiento directo
     * de stock (`set_stock_quantity`/`save`) — el plugin de Traspasos sigue
     * funcionando standalone, solo que sin kardex.
     *
     * @param int    $item_id     Variación cuyo stock cambia.
     * @param int    $qty         Cantidad (> 0).
     * @param string $direction   'I' (suma) | 'O' (resta).
     * @param string $tipo        IEM_Kardex::TYPE_TRANSFER_IN | TYPE_TRANSFER_OUT.
     * @param int    $transfer_id id de wp_wc_tp_history (referencia del kardex).
     * @param string $note        Nota legible (p. ej. "Traspaso → SANTA CRUZ").
     * @throws Exception si el movimiento falla (para abortar la transacción).
     */
    private static function _move_stock($item_id, $qty, $direction, $tipo, $transfer_id, $note)
    {
        $item_id = intval($item_id);
        $qty     = intval($qty);
        if ($item_id <= 0 || $qty <= 0) {
            return;
        }

        // Camino preferido: registrar en el Kardex de Inventario Ventova.
        if (class_exists('IEM_Kardex')) {
            $r = IEM_Kardex::record([
                'item_id'   => $item_id,
                'type'      => $tipo,
                'direction' => $direction,
                'qty'       => $qty,
                'ref_table' => 'wc_tp_history',
                'ref_id'    => intval($transfer_id),
                'ref_code'  => 'TRASP-' . intval($transfer_id),
                'notes'     => $note,
                'update_wc' => true, // mueve el stock de WC con guard (sin manual_wc).
            ]);
            if (is_wp_error($r)) {
                throw new Exception($r->get_error_message());
            }
            return;
        }

        // Fallback sin IEM: movimiento directo del stock de WC.
        $product = wc_get_product($item_id);
        if (!$product) {
            return;
        }
        $cur = (int) $product->get_stock_quantity();
        $new = ($direction === 'I') ? $cur + $qty : max(0, $cur - $qty);
        $product->set_stock_quantity($new);
        $product->save();
    }

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

            // Si la variación no gestiona stock, get_stock_quantity() devuelve
            // null; tratamos ese caso como error explícito en vez de un mensaje
            // con "Disponible: " vacío (con IEM esto ya lo cubre managing_stock()).
            if (!$product->managing_stock()) {
                throw new Exception("La variación «{$it['name']}» no gestiona stock; actívalo en WooCommerce antes de traspasar.");
            }

            $current_stock = (int) $product->get_stock_quantity();
            if ((int) $it['qty'] > $current_stock) {
                throw new Exception("Stock insuficiente para {$it['name']}. Disponible: $current_stock, Solicitado: {$it['qty']}");
            }
        }
    }

    private static function _apply_movement($items, $origen, $destino, $estado, $transfer_id = 0)
    {
        $dest_name = WC_TP_Config::get_sucursal_name($destino);

        // 1. Restar de origen (Siempre se hace al crear/aplicar)
        // NOTA: el tipo va como string literal (no IEM_Kardex::TYPE_*) para no
        // referenciar una constante de clase ausente cuando IEM no está activo.
        foreach ($items as $it) {
            self::_move_stock(
                $it['id'], $it['qty'], 'O',
                'transfer_out', $transfer_id, 'Traspaso → ' . $dest_name
            );
        }

        // 2. Si ya es recibido, sumar a destino
        if ($estado === 'Recibido') {
            foreach ($items as $it) {
                self::_add_stock_to_dest($it['id'], $it['qty'], $destino, $transfer_id, $origen);
            }
        }
    }

    private static function _revert_movement($items, $origen, $destino, $estado, $transfer_id = 0)
    {
        // Operación inversa a _apply_movement

        // 1. Sumar a origen (Devolver lo que se quitó)
        foreach ($items as $it) {
            self::_move_stock(
                $it['id'], $it['qty'], 'I',
                'transfer_in', $transfer_id, 'Reverso de traspaso (devolución a origen)'
            );
        }

        // 2. Si estaba recibido, restar de destino (Quitar lo que se entregó)
        if ($estado === 'Recibido') {
            foreach ($items as $it) {
                self::_remove_stock_from_dest($it['id'], $it['qty'], $destino, $transfer_id, 'Reverso de traspaso');
            }
        }
    }

    private static function _add_stock_to_dest($source_var_id, $qty, $dest_slug, $transfer_id = 0, $origen_slug = '')
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

        $origen_name = $origen_slug !== '' ? WC_TP_Config::get_sucursal_name($origen_slug) : 'origen';
        self::_move_stock(
            $target_var->get_id(), $qty, 'I',
            'transfer_in', $transfer_id, 'Traspaso desde ' . $origen_name
        );
    }

    private static function _remove_stock_from_dest($source_var_id, $qty, $dest_slug, $transfer_id = 0, $note = 'Reverso de traspaso')
    {
        $src = wc_get_product($source_var_id);
        if (!$src)
            return;

        $target_var = self::_find_variation_in_branch($src->get_parent_id(), $src, $dest_slug);
        if ($target_var) {
            self::_move_stock(
                $target_var->get_id(), $qty, 'O',
                'transfer_out', $transfer_id, $note
            );
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