<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kardex de inventario (v3.16+).
 *
 * Ledger central de movimientos. Nueve tipos cubren todas las fuentes:
 *
 *   opening       — apertura (backfill al instalar v1.5)
 *   purchase      — compra recibida (Fase 2)
 *   sale          — venta WC (hook woocommerce_reduce_order_stock)
 *   sale_refund   — devolución WC (hook woocommerce_restock_refunded_item)
 *   merma         — merma con decremento WC
 *   merma_return  — merma retornada al inventario
 *   manual_in     — ajuste manual de entrada (con nota obligatoria)
 *   manual_out    — ajuste manual de salida (con nota obligatoria)
 *   manual_wc     — edit de stock fuera del plugin (editor de WC, REST, etc.)
 *
 * Modelo de concurrencia / consistencia:
 *
 *  - `balance_after` = `(último balance_after del item_id) ± qty`. Calculado
 *    en cada insert leyendo la última fila del item. NO usa SELECT FOR UPDATE
 *    (wpdb no lo expone bien); confiamos en que:
 *      a) Las ops administrativas (compras, mermas, manual) son secuenciales.
 *      b) Las ventas WC corren dentro del flujo de checkout que también es
 *         secuencial por orden.
 *      c) En el peor caso de race, dos movs concurrentes pueden tener
 *         balance_after incorrectos; el saldo se corrige en el siguiente mov.
 *    Suficiente para una tienda de bajo volumen como Ventova.
 *
 *  - Las ops internas del plugin usan `update_wc_stock_silent` (con guard
 *    estático) para que el hook `woocommerce_product_set_stock` no las
 *    interprete como `manual_wc`.
 *
 *  - Detección de `sale` / `sale_refund` / `manual_wc`: usa un BUFFER por
 *    request. El catch-all `on_wc_stock_set` captura el cambio; los hooks
 *    específicos (`reduce_order_stock`, `restock_refunded_item`) clasifican
 *    el cambio. En `shutdown` se vuelca el buffer al kardex con el tipo
 *    correcto.
 */
class IEM_Kardex
{
    const TYPE_OPENING      = 'opening';
    const TYPE_PURCHASE     = 'purchase';
    const TYPE_SALE         = 'sale';
    const TYPE_SALE_REFUND  = 'sale_refund';
    const TYPE_MERMA        = 'merma';
    const TYPE_MERMA_RETURN = 'merma_return';
    const TYPE_MANUAL_IN    = 'manual_in';
    const TYPE_MANUAL_OUT   = 'manual_out';
    const TYPE_MANUAL_WC    = 'manual_wc';
    // 3.32+: traspasos entre sucursales (los registra el plugin de Traspasos
    // vía IEM_Kardex::record). OUT = salida del origen, IN = entrada al destino.
    const TYPE_TRANSFER_OUT = 'transfer_out';
    const TYPE_TRANSFER_IN  = 'transfer_in';

    /** Flag que silencia el hook catch-all cuando es el plugin quien tocó WC. */
    private static $in_internal_op = false;

    /**
     * Buffer per-request de cambios de stock detectados por el catch-all.
     * Formato: item_id => [item_id, product, old_balance, new_stock, source, ref_*, user_id].
     * Se vuelca en `shutdown`.
     */
    private static $pending = [];

    /** Mapa user-friendly de tipos. */
    public static function get_types()
    {
        return [
            self::TYPE_OPENING      => 'Apertura',
            self::TYPE_PURCHASE     => 'Compra',
            self::TYPE_SALE         => 'Venta',
            self::TYPE_SALE_REFUND  => 'Devolución',
            self::TYPE_MERMA        => 'Merma',
            self::TYPE_MERMA_RETURN => 'Merma retornada',
            self::TYPE_MANUAL_IN    => 'Ajuste manual (+)',
            self::TYPE_MANUAL_OUT   => 'Ajuste manual (-)',
            self::TYPE_MANUAL_WC    => 'Edit en WooCommerce',
            self::TYPE_TRANSFER_OUT => 'Traspaso (salida)',
            self::TYPE_TRANSFER_IN  => 'Traspaso (entrada)',
        ];
    }

    public static function init()
    {
        // Hook catch-all: cualquier cambio de stock que pase por WC se captura.
        add_action('woocommerce_product_set_stock',     [__CLASS__, 'on_wc_stock_set'], 999, 1);
        add_action('woocommerce_variation_set_stock',   [__CLASS__, 'on_wc_stock_set'], 999, 1);

        // Clasificadores: ajustan el tipo de la entrada buffereada.
        add_action('woocommerce_reduce_order_stock',    [__CLASS__, 'on_reduce_order_stock'], 10, 1);
        add_action('woocommerce_restock_refunded_item', [__CLASS__, 'on_restock_refunded_item'], 10, 5);

        // Volcado al kardex al final del request.
        add_action('shutdown',                          [__CLASS__, 'flush_pending'], 999);
    }

    // ── Punto de entrada para ops internas ────────────────────────────────

    /**
     * Cambia stock de WC con guard activo (no dispara `manual_wc`). NO inserta
     * en kardex; combina con `insert_movement` para registrar el movimiento.
     *
     * @return true|WP_Error
     */
    public static function update_wc_stock_silent($item_id, $qty, $direction)
    {
        $product = wc_get_product((int) $item_id);
        if (!$product) {
            return new WP_Error('iem_kardex_no_product', 'Producto no encontrado.');
        }
        if (!$product->managing_stock()) {
            return new WP_Error('iem_kardex_no_stock_mgmt',
                'El producto no gestiona stock; activa "Gestionar stock" antes de mover.');
        }
        $op = ($direction === 'I') ? 'increase' : 'decrease';

        self::$in_internal_op = true;
        $new_stock = wc_update_product_stock($product, (int) $qty, $op);
        self::$in_internal_op = false;

        // Marcar el item como "ya procesado" para que el catch-all que se haya
        // disparado durante esta llamada (priority 999 → quizá no en este orden,
        // pero defensivo) descarte cualquier residuo en el buffer.
        unset(self::$pending[(int) $item_id]);

        if ($new_stock === false || is_wp_error($new_stock)) {
            return new WP_Error('iem_kardex_wc_failed', 'No se pudo actualizar el stock de WooCommerce.');
        }
        return true;
    }

    /**
     * INSERTA una fila en el kardex calculando balance_after.
     *
     * @return int|WP_Error id insertado.
     */
    public static function insert_movement(array $args)
    {
        $args = array_merge([
            'item_id'       => 0,
            'type'          => '',
            'direction'     => '',
            'qty'           => 0,
            'unit_cost'     => null,
            'origin_cost'   => null,
            'sucursal_slug' => null,
            'ref_table'     => null,
            'ref_id'        => null,
            'ref_code'      => null,
            'notes'         => null,
            'user_id'       => null,
            'movement_date' => null,
        ], $args);

        $item_id = (int) $args['item_id'];
        $qty     = (int) $args['qty'];
        if ($item_id <= 0 || $qty <= 0) {
            return new WP_Error('iem_kardex_invalid', 'Movimiento inválido (item_id/qty).');
        }
        if (!in_array($args['direction'], ['I', 'O'], true)) {
            return new WP_Error('iem_kardex_invalid', 'Dirección inválida.');
        }
        if (!array_key_exists((string) $args['type'], self::get_types())) {
            return new WP_Error('iem_kardex_invalid', 'Tipo de movimiento no reconocido: ' . $args['type']);
        }

        // Resolver parent_id y sucursal si no vinieron.
        $product   = wc_get_product($item_id);
        $parent_id = 0;
        $sucursal  = (string) ($args['sucursal_slug'] ?? '');
        if ($product) {
            $parent_id = $product->is_type('variation') ? (int) $product->get_parent_id() : $item_id;
            if ($sucursal === '') {
                $sucursal = IEM_Sucursales::resolve_product_sucursal_slug($product);
            }
        }

        global $wpdb;
        $t = IEM_Schema::table('kardex');

        // Running balance: leer última fila del item.
        $last_balance = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT balance_after FROM $t WHERE item_id = %d ORDER BY id DESC LIMIT 1",
            $item_id
        ));
        $balance_after = $args['direction'] === 'I'
            ? $last_balance + $qty
            : $last_balance - $qty;

        $movement_date = $args['movement_date'] ?: current_time('mysql');
        $user_id       = $args['user_id'] !== null ? (int) $args['user_id'] : (int) get_current_user_id();

        $ok = $wpdb->insert($t, [
            'item_id'       => $item_id,
            'parent_id'     => (int) $parent_id,
            'sucursal_slug' => $sucursal,
            'movement_date' => (string) $movement_date,
            'type'          => (string) $args['type'],
            'direction'     => (string) $args['direction'],
            'qty'           => $qty,
            'unit_cost'     => is_null($args['unit_cost'])   ? null : (float) $args['unit_cost'],
            'origin_cost'   => is_null($args['origin_cost']) ? null : (float) $args['origin_cost'],
            'balance_after' => $balance_after,
            'ref_table'     => $args['ref_table'] !== null ? (string) $args['ref_table'] : null,
            'ref_id'        => $args['ref_id']    !== null ? (int)    $args['ref_id']    : null,
            'ref_code'      => $args['ref_code']  !== null ? (string) $args['ref_code']  : null,
            'user_id'       => $user_id,
            'notes'         => $args['notes'] !== null ? (string) $args['notes'] : null,
            'created_at'    => current_time('mysql'),
        ], [
            '%d','%d','%s','%s','%s','%s','%d','%f','%f','%d',
            '%s','%d','%s','%d','%s','%s',
        ]);
        if (!$ok) {
            return new WP_Error('iem_kardex_db_insert', 'No se pudo insertar el movimiento de kardex.');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Combo: cambia stock WC + inserta kardex en una sola llamada. Para ops
     * sencillas (compras, manual) donde no hay un id externo a referenciar
     * que requiera split.
     *
     * Si `update_wc` es true, también dispara `wc_update_product_stock` con
     * guard activo. Si false, solo inserta kardex (útil para ventas/refunds
     * donde WC ya cambió el stock).
     */
    public static function record(array $args)
    {
        if (!empty($args['update_wc'])) {
            $r = self::update_wc_stock_silent(
                (int) $args['item_id'],
                (int) $args['qty'],
                (string) $args['direction']
            );
            if (is_wp_error($r)) return $r;
        }
        return self::insert_movement($args);
    }

    // ── Movimientos manuales (servicio) ───────────────────────────────────

    /**
     * Registra un ajuste manual con nota obligatoria. Cambia stock WC también.
     *
     * @return int|WP_Error id del movimiento insertado.
     */
    public static function manual_movement(array $args)
    {
        $sku       = isset($args['sku'])       ? trim((string) $args['sku'])       : '';
        $direction = isset($args['direction']) ? strtoupper((string) $args['direction']) : '';
        $qty       = isset($args['qty'])       ? (int) $args['qty']                : 0;
        $notes     = isset($args['notes'])     ? trim((string) $args['notes'])     : '';

        if ($sku === '') {
            return new WP_Error('iem_kardex_invalid', 'Ingresa un SKU.');
        }
        if (!in_array($direction, ['I', 'O'], true)) {
            return new WP_Error('iem_kardex_invalid', 'Dirección inválida (usa I o O).');
        }
        if ($qty <= 0) {
            return new WP_Error('iem_kardex_invalid', 'La cantidad debe ser mayor a 0.');
        }
        if ($notes === '') {
            return new WP_Error('iem_kardex_notes_required',
                'La nota es obligatoria en movimientos manuales (motivo del ajuste).');
        }

        $id = wc_get_product_id_by_sku($sku);
        if ($id <= 0) {
            return new WP_Error('iem_sku_not_found', 'No se encontró el SKU.');
        }
        $product = wc_get_product($id);
        if (!$product) {
            return new WP_Error('iem_no_product', 'Producto no encontrado.');
        }
        if ($product->is_type('variable')) {
            return new WP_Error('iem_must_be_variation',
                'SKU corresponde a un producto variable; usa una variación.');
        }
        if (!$product->managing_stock()) {
            return new WP_Error('iem_no_stock_mgmt',
                'El producto no gestiona stock.');
        }

        return self::record([
            'item_id'       => (int) $id,
            'type'          => $direction === 'I' ? self::TYPE_MANUAL_IN : self::TYPE_MANUAL_OUT,
            'direction'     => $direction,
            'qty'           => $qty,
            'sucursal_slug' => IEM_Sucursales::resolve_product_sucursal_slug($product),
            'notes'         => $notes,
            'update_wc'     => true,
        ]);
    }

    // ── Hooks WC: detección automática ────────────────────────────────────

    /**
     * Catch-all: capturar cualquier cambio de stock que pase por WC.
     * Si es operación interna del plugin, ignorar.
     * Si ya hay una entrada en el buffer para este item, refrescar new_stock.
     */
    public static function on_wc_stock_set($product)
    {
        if (self::$in_internal_op)      return;
        if (!$product || !is_object($product)) return;

        // 3.21+: los padres variables son agregados derivados (stock = Σ
        // variaciones, calculado por el child theme `ventova-store-child` en
        // `inc/woocommerce-custom.php`). No son items operables de inventario
        // — sus "cambios" de stock son siempre consecuencia de un cambio en
        // una de sus variaciones, no movimientos reales. Los saltamos para
        // mantener el kardex como ledger 1-a-1 con cosas que realmente tienen
        // stock (variaciones o productos simples).
        if (method_exists($product, 'is_type') && $product->is_type('variable')) {
            return;
        }

        $item_id = (int) $product->get_id();
        if ($item_id <= 0) return;
        $new_stock = (int) $product->get_stock_quantity();

        if (isset(self::$pending[$item_id])) {
            self::$pending[$item_id]['new_stock'] = $new_stock;
            self::$pending[$item_id]['product']   = $product;
            return;
        }

        // Primera captura del item en este request: snapshotear el balance previo.
        global $wpdb;
        $t = IEM_Schema::table('kardex');
        $old_balance = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT balance_after FROM $t WHERE item_id = %d ORDER BY id DESC LIMIT 1",
            $item_id
        ));

        self::$pending[$item_id] = [
            'item_id'     => $item_id,
            'product'     => $product,
            'old_balance' => $old_balance,
            'new_stock'   => $new_stock,
            'source'      => self::TYPE_MANUAL_WC,
            'ref_table'   => null,
            'ref_id'      => null,
            'ref_code'    => null,
            'user_id'     => (int) get_current_user_id(),
        ];
    }

    /**
     * Clasificador de ventas. Itera los items del pedido y reclasifica las
     * entradas del buffer (que el catch-all ya capturó como `manual_wc`).
     */
    public static function on_reduce_order_stock($order)
    {
        if (!is_a($order, 'WC_Order')) return;
        $order_id   = (int) $order->get_id();
        $order_code = (string) $order->get_order_number();
        $customer   = (int) $order->get_customer_id();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $id = (int) $product->get_id();
            if (!isset(self::$pending[$id])) continue;
            self::$pending[$id]['source']    = self::TYPE_SALE;
            self::$pending[$id]['ref_table'] = 'shop_order';
            self::$pending[$id]['ref_id']    = $order_id;
            self::$pending[$id]['ref_code']  = $order_code;
            if ($customer > 0) {
                self::$pending[$id]['user_id'] = $customer;
            }
        }
    }

    /**
     * Clasificador de devoluciones. Fire por cada item con stock restituido
     * desde un refund.
     */
    public static function on_restock_refunded_item($product_id, $old_stock_orig, $new_stock_orig, $order, $product)
    {
        $product_id = (int) $product_id;
        if (!isset(self::$pending[$product_id])) return;
        self::$pending[$product_id]['source']    = self::TYPE_SALE_REFUND;
        if (is_a($order, 'WC_Order')) {
            self::$pending[$product_id]['ref_table'] = 'shop_order';
            self::$pending[$product_id]['ref_id']    = (int) $order->get_id();
            self::$pending[$product_id]['ref_code']  = (string) $order->get_order_number();
        }
    }

    /**
     * Volcado al final del request. Inserta una fila de kardex por cada
     * entrada del buffer cuyo delta sea distinto de 0.
     */
    public static function flush_pending()
    {
        if (empty(self::$pending)) return;
        foreach (self::$pending as $item_id => $entry) {
            $delta = (int) $entry['new_stock'] - (int) $entry['old_balance'];
            if ($delta === 0) continue;

            $direction = $delta > 0 ? 'I' : 'O';
            $qty       = abs($delta);

            self::insert_movement([
                'item_id'   => (int) $item_id,
                'type'      => (string) $entry['source'],
                'direction' => $direction,
                'qty'       => $qty,
                'unit_cost' => self::snapshot_cost((int) $item_id),
                'ref_table' => $entry['ref_table'],
                'ref_id'    => $entry['ref_id'],
                'ref_code'  => $entry['ref_code'],
                'user_id'   => (int) $entry['user_id'],
            ]);
        }
        self::$pending = [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Lee `_alg_wc_cog_cost` del item; fallback al padre si es variación. */
    public static function snapshot_cost($item_id)
    {
        $cost = get_post_meta((int) $item_id, '_alg_wc_cog_cost', true);
        if ($cost === '' || $cost === null) {
            $p = wc_get_product((int) $item_id);
            if ($p && $p->is_type('variation')) {
                $cost = get_post_meta((int) $p->get_parent_id(), '_alg_wc_cog_cost', true);
            }
        }
        return ($cost === '' || $cost === null) ? null : (float) $cost;
    }

    // ── Lectura / reportes ────────────────────────────────────────────────

    /** Movimientos de un item en orden cronológico ASC. */
    /**
     * Listado plano de movimientos para export CSV (Fase 4.2+).
     * Acepta filtros: from/to (movement_date), type, sucursal_slug, item_id.
     * Sin límite por defecto (cuidado con result-sets gigantes; el export es
     * streaming pero el query carga todo en memoria).
     *
     * @return array Filas de iem_kardex en orden cronológico ASC.
     */
    public static function query_movements(array $args = [])
    {
        global $wpdb;
        $t      = IEM_Schema::table('kardex');
        $where  = ['1=1'];
        $params = [];

        if (!empty($args['from']))          { $where[] = 'movement_date >= %s'; $params[] = $args['from']; }
        if (!empty($args['to']))            { $where[] = 'movement_date <= %s'; $params[] = $args['to']; }
        if (!empty($args['type']))          { $where[] = 'type = %s';            $params[] = $args['type']; }
        if (!empty($args['sucursal_slug'])) { $where[] = 'sucursal_slug = %s';   $params[] = $args['sucursal_slug']; }
        if (!empty($args['item_id']))       { $where[] = 'item_id = %d';         $params[] = (int) $args['item_id']; }

        $limit  = isset($args['limit']) ? max(1, (int) $args['limit']) : 50000;
        $sql    = "SELECT * FROM $t WHERE " . implode(' AND ', $where)
                . " ORDER BY movement_date ASC, id ASC LIMIT $limit";
        $sql    = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function movements_for_item($item_id, array $args = [])
    {
        global $wpdb;
        $t       = IEM_Schema::table('kardex');
        $item_id = (int) $item_id;
        $where   = ['item_id = %d'];
        $params  = [$item_id];

        if (!empty($args['from'])) { $where[] = 'movement_date >= %s'; $params[] = $args['from']; }
        if (!empty($args['to']))   { $where[] = 'movement_date <= %s'; $params[] = $args['to']; }
        if (!empty($args['type'])) { $where[] = 'type = %s';           $params[] = $args['type']; }

        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 1000;
        $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where)
             . " ORDER BY id ASC LIMIT $limit";
        return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
    }

    /**
     * Mapa item_id => última fila (incluye balance_after, fecha, tipo).
     * Usado por la vista "saldo actual".
     *
     * Filtros: sucursal, search SKU. NO se incluyen items con 0 movimientos.
     */
    public static function current_balances(array $args = [])
    {
        global $wpdb;
        $t       = IEM_Schema::table('kardex');
        $where   = ['1=1'];
        $params  = [];

        if (!empty($args['sucursal_slug'])) {
            $where[]  = 'k.sucursal_slug = %s';
            $params[] = $args['sucursal_slug'];
        }

        $where_sql = implode(' AND ', $where);
        $limit     = isset($args['limit']) ? max(1, (int) $args['limit']) : 1000;

        // Última fila por item: subquery con MAX(id).
        $sql = "SELECT k.*
                FROM $t k
                JOIN (
                    SELECT item_id, MAX(id) AS max_id FROM $t GROUP BY item_id
                ) m ON k.id = m.max_id
                WHERE $where_sql
                ORDER BY k.movement_date DESC, k.id DESC
                LIMIT $limit";
        $sql = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Vista admin del kardex (3.22+): listado de saldos con filtros por
     * nombre de producto y por categoría (term_id de `product_cat`).
     *
     * Reglas:
     *  - 3.24+: NO hay "search-first". El listado se devuelve siempre (hasta
     *    `limit`). La búsqueda por texto se ejecuta client-side sobre la
     *    tabla; el server solo aplica `sucursal_slug` y opcionalmente
     *    `category_id`.
     *  - El filtro por sucursal usa EXISTS sobre el kardex del item: muestra
     *    ítems con AL MENOS un movimiento en esa sucursal (no exige que la
     *    última fila esté ahí). Esto resuelve la combinación con búsqueda /
     *    categoría cuando un ítem se mueve entre sucursales (3.23+).
     *  - Se devuelve también `sku` (vía `_sku` postmeta) para que el filtro
     *    client-side pueda matchear nombre y SKU sin queries extra.
     *  - JOIN con `wp_posts` para resolver el `name` del item (variación) y
     *    su `parent_id`; LEFT JOIN con el padre para variaciones (búsqueda
     *    por nombre del padre y filtro de categoría sobre el padre, ya que
     *    las categorías viven en el padre).
     *  - Para productos simples la categoría se busca contra el propio item.
     *
     * Devuelve filas como `current_balances()` + columnas extra:
     *   - `name`       → post_title del item (variación) o '' si no existe.
     *   - `parent_id`  → padre si es variación, 0 si es simple.
     *
     * Las categorías legibles se resuelven en `categories_for_items()` en un
     * batch separado para alimentar las chips client-side.
     */
    public static function current_balances_for_view(array $args = [])
    {
        $search      = trim((string) ($args['search']      ?? ''));
        $category_id = (int)            ($args['category_id'] ?? 0);
        $sucursal    = trim((string) ($args['sucursal_slug'] ?? ''));

        // 3.24+: sin guard search-first. El listado siempre carga (hasta limit).
        global $wpdb;
        $t     = IEM_Schema::table('kardex');
        $where = ['1=1', "p.post_status = 'publish'"];
        $params = [];

        // Filtro por sucursal: items que tengan AL MENOS un movimiento en esa
        // sucursal. NO se aplica sobre la fila MAX(id) (eso descartaría items
        // cuyo último mov. fue en otra sucursal pero sí tienen historia aquí).
        if ($sucursal !== '') {
            $where[]  = "EXISTS (
                SELECT 1 FROM $t k2
                WHERE k2.item_id = k.item_id AND k2.sucursal_slug = %s
            )";
            $params[] = $sucursal;
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            // Busca en el título del item y en el del padre (para variaciones).
            $where[]  = '(p.post_title LIKE %s OR pp.post_title LIKE %s OR p.ID = %d)';
            $params[] = $like;
            $params[] = $like;
            $params[] = ctype_digit($search) ? (int) $search : 0;
        }

        if ($category_id > 0) {
            // Categoría puede vivir sobre el item simple o sobre el padre de
            // la variación. Resolvemos contra ambos casos con un solo EXISTS.
            $where[]  = "EXISTS (
                SELECT 1
                FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tr.object_id IN (
                    p.ID,
                    CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE 0 END
                )
                AND tt.taxonomy = 'product_cat'
                AND tt.term_id  = %d
            )";
            $params[] = $category_id;
        }

        $where_sql = implode(' AND ', $where);
        $limit     = isset($args['limit']) ? max(1, (int) $args['limit']) : 1000;

        $sql = "SELECT k.*,
                       p.post_title AS name,
                       COALESCE(sku.meta_value, '') AS sku,
                       CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE 0 END AS parent_id,
                       pp.post_title AS parent_name
                FROM $t k
                JOIN (
                    SELECT item_id, MAX(id) AS max_id FROM $t GROUP BY item_id
                ) m  ON k.id = m.max_id
                JOIN {$wpdb->posts} p  ON p.ID = k.item_id
                LEFT JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent
                                            AND p.post_type = 'product_variation'
                LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID
                                              AND sku.meta_key  = '_sku'
                WHERE $where_sql
                ORDER BY k.movement_date DESC, k.id DESC
                LIMIT $limit";

        $sql = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Devuelve `item_id => [cat_name, cat_name, ...]` para las chips del
     * filtro por categoría en la vista admin (3.22+).
     *
     * Para variaciones se leen las categorías del padre (las variaciones no
     * tienen `product_cat` propio). Para simples se leen las del item.
     *
     * Un solo query batch sobre `term_relationships` + `term_taxonomy` + `terms`
     * para todos los item_ids visibles. Idempotente y O(N) con N = items.
     *
     * @param int[]   $item_ids
     * @param int[]   $parent_ids  Para variaciones (en el mismo orden que $item_ids).
     * @return array  item_id => string[] de nombres de categoría.
     */
    public static function categories_for_items(array $item_ids, array $parent_ids = [])
    {
        if (empty($item_ids)) return [];
        global $wpdb;

        // Set único de objetos a consultar: item_id ∪ parent_id (de variaciones).
        $object_ids = array_unique(array_filter(array_map('intval',
            array_merge($item_ids, $parent_ids)
        )));
        if (empty($object_ids)) return [];

        $ph = implode(',', array_fill(0, count($object_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.object_id, t.name AS cat
             FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             JOIN {$wpdb->terms} t          ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'product_cat'
               AND tr.object_id IN ($ph)",
            ...$object_ids
        ), ARRAY_A);

        $by_object = [];
        foreach ($rows as $r) {
            $by_object[(int) $r['object_id']][] = (string) $r['cat'];
        }

        $out = [];
        foreach ($item_ids as $i => $item_id) {
            $item_id   = (int) $item_id;
            $parent_id = (int) ($parent_ids[$i] ?? 0);
            $cats = [];
            if (isset($by_object[$item_id])) {
                $cats = array_merge($cats, $by_object[$item_id]);
            }
            if ($parent_id > 0 && isset($by_object[$parent_id])) {
                $cats = array_merge($cats, $by_object[$parent_id]);
            }
            $cats = array_values(array_unique($cats));
            $out[$item_id] = $cats;
        }
        return $out;
    }
}
