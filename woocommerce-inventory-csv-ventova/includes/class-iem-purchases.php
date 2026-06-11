<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CRUD de compras + recepción atómica (v3.15+; rediseño 3.18).
 *
 * Modelo conceptual (3.18+):
 *
 *   - Las líneas referencian **productos padre** (simple o variable parent),
 *     NO variaciones. Se elige el padre al armar el draft.
 *   - Al **recibir** se decide la variación destino por cada línea (sólo si
 *     el padre es variable). Para simples no hay elección. El id de la
 *     variación elegida se persiste en `received_variation_id` y es contra
 *     ese item donde se suma stock + actualiza costo + se registra el kardex.
 *
 *   draft  ─── add/save/delete lines ───►  draft   (editable libre)
 *      │
 *      └─── receive(map) ─────►  received   (terminal, sin reversa)
 *
 *   delete()  solo permitido en draft.
 *
 * El código `COMP-YYYY-NNNN` se autogenera por año, con padding 4. El año se
 * toma de `purchase_date`; el NNNN es secuencial dentro del año (no global)
 * derivado de MAX por LIKE de prefijo.
 */
class IEM_Purchases
{
    /** Devuelve la fila o null. */
    public static function get($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        global $wpdb;
        $t = IEM_Schema::table('purchases');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id), ARRAY_A) ?: null;
    }

    /** Devuelve líneas de una compra ordenadas por id (orden de inserción). */
    public static function get_lines($purchase_id)
    {
        global $wpdb;
        $t = IEM_Schema::table('purchase_lines');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE purchase_id = %d ORDER BY id ASC",
            (int) $purchase_id
        ), ARRAY_A);
        return $rows ?: [];
    }

    /** Recepciones de una compra agrupadas por line_id: [line_id => [filas]]. */
    public static function get_receipts_by_line($purchase_id)
    {
        global $wpdb;
        $t = IEM_Schema::table('purchase_line_receipts');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE purchase_id = %d ORDER BY line_id ASC, id ASC",
            (int) $purchase_id
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['line_id']][] = $r;
        }
        return $out;
    }

    /** Listado filtrable. */
    public static function query(array $args = [])
    {
        global $wpdb;
        $t = IEM_Schema::table('purchases');

        $where  = ['1=1'];
        $params = [];
        if (!empty($args['status']))         { $where[] = 'status = %s';         $params[] = $args['status']; }
        if (!empty($args['supplier_id'])) {
            // 2.1+: el proveedor vive por línea; una compra coincide si la
            // cabecera o ALGUNA de sus líneas usa ese proveedor.
            $tl = IEM_Schema::table('purchase_lines');
            $where[]  = "(supplier_id = %d OR EXISTS (SELECT 1 FROM $tl pl WHERE pl.purchase_id = $t.id AND pl.supplier_id = %d))";
            $params[] = (int) $args['supplier_id'];
            $params[] = (int) $args['supplier_id'];
        }
        if (!empty($args['sucursal_slug']))  { $where[] = 'sucursal_slug = %s';  $params[] = $args['sucursal_slug']; }
        if (!empty($args['from']))           { $where[] = 'purchase_date >= %s'; $params[] = $args['from']; }
        if (!empty($args['to']))             { $where[] = 'purchase_date <= %s'; $params[] = $args['to']; }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = '(code LIKE %s OR invoice_number LIKE %s)';
            $params[] = $like; $params[] = $like;
        }

        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 500;
        $sql   = "SELECT * FROM $t WHERE " . implode(' AND ', $where)
               . " ORDER BY purchase_date DESC, id DESC LIMIT $limit";
        $sql   = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Proveedores DISTINTOS de cada compra (el proveedor es por línea desde 2.1).
     * Para el listado. Devuelve [purchase_id => [nombre, ...]] ordenado.
     *
     * @param int[] $purchase_ids
     * @param array $names_map  [supplier_id => name] para resolver nombres.
     * @return array
     */
    public static function suppliers_by_purchase(array $purchase_ids, array $names_map = [])
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $purchase_ids))));
        if (empty($ids)) {
            return [];
        }
        global $wpdb;
        $tl  = IEM_Schema::table('purchase_lines');
        $in  = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT purchase_id, supplier_id FROM $tl
             WHERE purchase_id IN ($in) AND supplier_id > 0",
            ...$ids
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $pid  = (int) $r['purchase_id'];
            $sid  = (int) $r['supplier_id'];
            $out[$pid][] = $names_map[$sid] ?? ('#' . $sid);
        }
        foreach ($out as &$arr) {
            sort($arr, SORT_NATURAL | SORT_FLAG_CASE);
        }
        unset($arr);
        return $out;
    }

    /**
     * Costo de origen total (USD) de cada compra = Σ qty × origin_cost de sus
     * líneas. Para el listado. Devuelve [purchase_id => float].
     *
     * @param int[] $purchase_ids
     * @return array
     */
    public static function origin_totals_by_purchase(array $purchase_ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $purchase_ids))));
        if (empty($ids)) {
            return [];
        }
        global $wpdb;
        $tl  = IEM_Schema::table('purchase_lines');
        $in  = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT purchase_id, COALESCE(SUM(qty * origin_cost), 0) AS t
             FROM $tl WHERE purchase_id IN ($in) GROUP BY purchase_id",
            ...$ids
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['purchase_id']] = round((float) $r['t'], 2);
        }
        return $out;
    }

    /**
     * Genera el siguiente código del año. Formato: COMP-YYYY-NNNN.
     *
     * NO atómico — entre la lectura del MAX y el INSERT puede colarse otra
     * compra. La UNIQUE KEY en `code` previene corrupción: el segundo INSERT
     * fallaría y se reintentaría regenerando el código. En la práctica el
     * volumen es bajo y suficiente para evitar colisiones.
     */
    public static function next_code($purchase_date_ymd)
    {
        global $wpdb;
        $t      = IEM_Schema::table('purchases');
        $year   = substr((string) $purchase_date_ymd, 0, 4);
        if (!preg_match('/^\d{4}$/', $year)) {
            $year = wp_date('Y');
        }
        $prefix = 'COMP-' . $year . '-';
        $like   = $wpdb->esc_like($prefix) . '%';

        // Toma el correlativo máximo del año mirando los últimos 4 chars como entero.
        $max = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING(code, %d) AS UNSIGNED))
             FROM $t WHERE code LIKE %s",
            strlen($prefix) + 1, $like
        ));
        $next = ((int) $max) + 1;
        return sprintf('%s%04d', $prefix, $next);
    }

    /**
     * Resuelve un producto padre para usarlo como línea de compra.
     *
     * Reglas (3.18+):
     *  - Acepta productos `simple` y `variable` (padres).
     *  - Rechaza `variation` (al armar el draft NO se elige variación;
     *    eso ocurre al recibir).
     *  - Rechaza otros tipos (grouped, external, etc.).
     *
     * @param int $product_id ID del producto padre.
     * @return array|WP_Error
     */
    public static function resolve_for_purchase($product_id)
    {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return new WP_Error('iem_product_empty', 'Selecciona un producto.');
        }
        $p = wc_get_product($product_id);
        if (!$p) {
            return new WP_Error('iem_no_product', 'Producto no encontrado.');
        }
        if ($p->is_type('variation')) {
            return new WP_Error('iem_must_be_parent',
                'Las compras referencian productos padre, no variaciones. Elige el producto padre.');
        }
        if (!$p->is_type('simple') && !$p->is_type('variable')) {
            return new WP_Error('iem_unsupported_type',
                'Tipo de producto no soportado en compras (solo simple o variable).');
        }
        $is_variable = $p->is_type('variable');

        // Sugerencia de costo del padre.
        $cost = get_post_meta($product_id, '_alg_wc_cog_cost', true);

        // Sugerencia del costo de origen (Valor Exw USD), meta `costo_de_origen`
        // del producto padre (ver ventova-store-child/inc/woocommerce-product-columns.php).
        $origin = get_post_meta($product_id, 'costo_de_origen', true);

        return [
            'item_id'      => $product_id,
            'parent_id'    => 0,
            'name'         => (string) $p->get_name(),
            'sku'          => (string) $p->get_sku(),
            'is_variable'  => $is_variable,
            'current_cost' => ($cost === '' || $cost === null) ? null : (float) $cost,
            'origin_cost'  => ($origin === '' || $origin === null) ? null : (float) $origin,
        ];
    }

    /**
     * Devuelve las variaciones de un producto variable como
     * `[ ['id' => int, 'label' => string, 'sucursal_slug' => string], ... ]`.
     *
     * `label` combina los atributos resueltos (vía `wc_get_formatted_variation`)
     * con el SKU si lo hay. Pensado para alimentar dropdowns en el form de
     * recepción.
     *
     * @return array
     */
    public static function get_variations_for_parent($parent_id)
    {
        $parent_id = (int) $parent_id;
        $p = $parent_id > 0 ? wc_get_product($parent_id) : null;
        if (!$p || !$p->is_type('variable')) {
            return [];
        }

        $out = [];
        $children = $p->get_children();
        foreach ($children as $child_id) {
            $v = wc_get_product((int) $child_id);
            if (!$v || !$v->is_type('variation')) continue;

            $attrs = wc_get_formatted_variation($v, true, false);
            $sku   = (string) $v->get_sku();
            $label = trim((string) $attrs);
            if ($sku !== '') {
                $label = '[' . $sku . '] ' . $label;
            }
            if ($label === '') {
                $label = 'Variación #' . (int) $child_id;
            }

            $sucursal_slug = '';
            $raw = $v->get_attribute('pa_sucursal');
            if ($raw !== '') {
                // get_attribute() devuelve nombres separados por coma; tomamos el primero.
                $parts = array_map('trim', explode(',', $raw));
                $first = $parts[0] ?? '';
                if ($first !== '' && class_exists('IEM_Sucursales')) {
                    $slug = IEM_Sucursales::find_slug_by_name($first);
                    $sucursal_slug = $slug ?: '';
                }
            }

            // 2.1+: color de la variación (atributo `pa_color`), para la
            // recepción dividida por color dentro de una misma sucursal.
            $color = trim((string) $v->get_attribute('pa_color'));

            $out[] = [
                'id'            => (int) $child_id,
                'label'         => $label,
                'sucursal_slug' => $sucursal_slug,
                'color'         => $color,
            ];
        }
        return $out;
    }

    /**
     * ¿El producto padre variable tiene variación de COLOR (atributo `pa_color`)
     * además de la de sucursal? Determina si la recepción ofrece el reparto de
     * cantidad por color. 2.1+.
     */
    public static function parent_has_color($parent_id)
    {
        foreach (self::get_variations_for_parent($parent_id) as $v) {
            if (($v['color'] ?? '') !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Para cada item_id (variación o producto simple), devuelve la **última
     * compra recibida** que entregó stock a ese item. Fase 4.1+ — alimenta la
     * columna "Última compra" del Inventario.
     *
     * Resolución del destino real de cada línea de compra:
     *   - Si `received_variation_id` > 0 → ese es el destino (compra de variable).
     *   - Si `received_variation_id` = 0 → el destino es el `item_id` de la
     *     línea (compra de simple, donde la línea referencia al producto mismo).
     *
     * Solo considera compras con `status = 'received'`. El criterio "más
     * reciente" es por `received_date DESC, pl.id DESC` (desempate estable).
     *
     * @param int[] $item_ids IDs de variación o de producto simple.
     * @return array<int, array{
     *   received_date:string, unit_cost:float, qty:int,
     *   purchase_id:int, purchase_code:string, supplier_id:int
     * }>  Keys = item_id; ítems sin compras NO aparecen.
     */
    public static function last_received_by_item(array $item_ids)
    {
        $item_ids = array_values(array_unique(array_filter(array_map('intval', $item_ids))));
        if (empty($item_ids)) return [];

        global $wpdb;
        $tl = IEM_Schema::table('purchase_lines');
        $tp = IEM_Schema::table('purchases');
        $tr = IEM_Schema::table('purchase_line_receipts');
        $ph = implode(',', array_fill(0, count($item_ids), '%d'));

        // 2.1+: la fuente canónica de "qué item recibió" es la tabla de
        // recepciones (una fila por variación destino, con su qty). El costo y el
        // proveedor vienen de la línea.
        $sql = "SELECT r.variation_id AS resolved_item,
                       pl.unit_cost, r.qty, r.purchase_id,
                       p.received_date, p.code AS purchase_code, pl.supplier_id
                FROM $tr r
                JOIN $tl pl ON pl.id = r.line_id
                JOIN $tp p  ON p.id  = r.purchase_id
                WHERE p.status = 'received'
                  AND r.variation_id IN ($ph)
                ORDER BY p.received_date DESC, r.id DESC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$item_ids), ARRAY_A);
        if (!$rows) return [];

        $out = [];
        foreach ($rows as $r) {
            $iid = (int) $r['resolved_item'];
            if (isset($out[$iid])) continue; // rows en DESC → la primera ya es la más reciente
            $out[$iid] = [
                'received_date' => (string) $r['received_date'],
                'unit_cost'     => (float)  $r['unit_cost'],
                'qty'           => (int)    $r['qty'],
                'purchase_id'   => (int)    $r['purchase_id'],
                'purchase_code' => (string) $r['purchase_code'],
                'supplier_id'   => (int)    $r['supplier_id'],
            ];
        }
        return $out;
    }

    // ── Crear / actualizar header ─────────────────────────────────────────

    /**
     * Crea una nueva compra en estado draft. Si $args['code'] está vacío, lo
     * autogenera. Si viene un `code` manual, valida UNIQUE.
     *
     * @return int|WP_Error id de la compra creada.
     */
    public static function create_draft(array $args)
    {
        $args = self::sanitize_header_args($args);
        if (is_wp_error($args)) return $args;

        $code = $args['code'];
        if ($code === '') {
            $code = self::next_code($args['purchase_date']);
        }

        global $wpdb;
        $t  = IEM_Schema::table('purchases');
        $ok = $wpdb->insert($t, [
            'code'            => $code,
            'supplier_id'     => (int) $args['supplier_id'],
            'sucursal_slug'   => (string) $args['sucursal_slug'],
            'status'          => 'draft',
            'purchase_date'   => (string) $args['purchase_date'],
            'received_date'   => null,
            'invoice_number'  => (string) $args['invoice_number'],
            'total'           => 0,
            'package_count'   => (int) $args['package_count'],
            'gross_weight_kg' => (float) $args['gross_weight_kg'],
            'cbm_m3'          => (float) $args['cbm_m3'],
            'shipping_via'    => (string) $args['shipping_via'],
            'tracking_number' => (string) $args['tracking_number'],
            'eta_date'        => $args['eta_date'] !== '' ? (string) $args['eta_date'] : null,
            'notes'           => (string) $args['notes'],
            'created_by'      => (int) get_current_user_id(),
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ], ['%s','%d','%s','%s','%s','%s','%s','%f','%d','%f','%f','%s','%s','%s','%s','%d','%s','%s']);
        if (!$ok) {
            // Probable: colisión de code UNIQUE — un retry con next_code resolvería,
            // pero si el operador puso un code manual, mejor que vea el mensaje.
            return new WP_Error('iem_db_insert', 'No se pudo crear la compra (¿código duplicado?).');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Actualiza header de una compra en draft. NO se puede cambiar status
     * por aquí (eso es `receive()`). El `code` sí es editable mientras draft.
     */
    public static function update_header($id, array $args)
    {
        $row = self::get((int) $id);
        if (!$row) {
            return new WP_Error('iem_not_found', 'Compra no encontrada.');
        }
        if ($row['status'] !== 'draft') {
            return new WP_Error('iem_locked', 'La compra ya fue recibida; no se puede modificar.');
        }
        $args = self::sanitize_header_args($args);
        if (is_wp_error($args)) return $args;

        $code = $args['code'];
        if ($code === '') {
            $code = $row['code']; // mantener el actual si lo borraron del form.
        }

        global $wpdb;
        $t  = IEM_Schema::table('purchases');
        $ok = $wpdb->update($t, [
            'code'            => $code,
            'supplier_id'     => (int) $args['supplier_id'],
            'sucursal_slug'   => (string) $args['sucursal_slug'],
            'purchase_date'   => (string) $args['purchase_date'],
            'invoice_number'  => (string) $args['invoice_number'],
            'package_count'   => (int) $args['package_count'],
            'gross_weight_kg' => (float) $args['gross_weight_kg'],
            'cbm_m3'          => (float) $args['cbm_m3'],
            'shipping_via'    => (string) $args['shipping_via'],
            'tracking_number' => (string) $args['tracking_number'],
            'eta_date'        => $args['eta_date'] !== '' ? (string) $args['eta_date'] : null,
            'notes'           => (string) $args['notes'],
            'updated_at'      => current_time('mysql'),
        ], ['id' => (int) $id],
        ['%s','%d','%s','%s','%s','%d','%f','%f','%s','%s','%s','%s','%s'], ['%d']);
        if ($ok === false) {
            return new WP_Error('iem_db_update', 'No se pudo actualizar la compra (¿código duplicado?).');
        }
        return true;
    }

    /** Borra una compra (solo en draft). Borra también sus líneas. */
    public static function delete($id)
    {
        $row = self::get((int) $id);
        if (!$row) {
            return new WP_Error('iem_not_found', 'Compra no encontrada.');
        }
        if ($row['status'] !== 'draft') {
            return new WP_Error('iem_locked', 'Solo se pueden borrar compras en borrador.');
        }
        // 3.36+: reversar (contrasiento) y borrar los gastos de importación
        // adjuntados antes de eliminar la compra, para no dejar egresos huérfanos.
        if (class_exists('IEM_Import_Costs')) {
            IEM_Import_Costs::reverse_all_for_purchase((int) $id);
        }
        global $wpdb;
        $wpdb->delete(IEM_Schema::table('purchase_lines'), ['purchase_id' => (int) $id], ['%d']);
        $wpdb->delete(IEM_Schema::table('purchases'),       ['id' => (int) $id],           ['%d']);
        return true;
    }

    // ── Líneas ────────────────────────────────────────────────────────────

    /**
     * Agrega una línea a una compra en draft. Resuelve el producto padre para
     * snapshotear `item_id`, `sku` y `name`. `parent_id` y `received_variation_id`
     * quedan en 0 (este último se llena al recibir).
     *
     * @param int   $purchase_id
     * @param array $args { product_id, qty, unit_cost, origin_cost, supplier_id }
     * @return array|WP_Error la línea recién insertada (formato fila de BD).
     */
    public static function add_line($purchase_id, array $args)
    {
        $row = self::get((int) $purchase_id);
        if (!$row) {
            return new WP_Error('iem_not_found', 'Compra no encontrada.');
        }
        if ($row['status'] !== 'draft') {
            return new WP_Error('iem_locked', 'La compra ya fue recibida.');
        }
        $product_id = (int) ($args['product_id'] ?? 0);
        $r = self::resolve_for_purchase($product_id);
        if (is_wp_error($r)) return $r;

        // 2.1+: proveedor por línea (obligatorio). Si el form no lo manda, cae al
        // proveedor por defecto de la cabecera.
        $supplier_id = (int) ($args['supplier_id'] ?? 0);
        if ($supplier_id <= 0) {
            $supplier_id = (int) $row['supplier_id'];
        }
        $sup_err = self::validate_line_supplier($supplier_id);
        if (is_wp_error($sup_err)) return $sup_err;

        $qty         = (int) ($args['qty'] ?? 0);
        $unit_cost   = (float) ($args['unit_cost'] ?? 0);
        // Costo de origen (Valor Exw USD). Si el form no lo manda, se siembra con
        // la sugerencia del producto (meta actual). Nunca negativo.
        $origin_cost = array_key_exists('origin_cost', $args)
            ? (float) $args['origin_cost']
            : (float) ($r['origin_cost'] ?? 0);
        if ($qty <= 0) {
            return new WP_Error('iem_invalid_qty', 'La cantidad debe ser mayor a 0.');
        }
        if ($unit_cost < 0) {
            return new WP_Error('iem_invalid_cost', 'El costo no puede ser negativo.');
        }
        if ($origin_cost < 0) {
            return new WP_Error('iem_invalid_origin_cost', 'El costo de origen no puede ser negativo.');
        }
        $line_total = round($qty * $unit_cost, 2);

        global $wpdb;
        $t  = IEM_Schema::table('purchase_lines');
        $ok = $wpdb->insert($t, [
            'purchase_id'           => (int) $purchase_id,
            'item_id'               => (int) $r['item_id'],
            'parent_id'             => 0,
            'supplier_id'           => $supplier_id,
            'received_variation_id' => 0,
            'sku'                   => (string) $r['sku'],
            'name'                  => (string) $r['name'],
            'qty'                   => $qty,
            'unit_cost'             => $unit_cost,
            'origin_cost'           => $origin_cost,
            'line_total'            => $line_total,
        ], ['%d','%d','%d','%d','%d','%s','%s','%d','%f','%f','%f']);
        if (!$ok) {
            return new WP_Error('iem_db_insert', 'No se pudo agregar la línea.');
        }
        $line_id = (int) $wpdb->insert_id;
        self::recalc_total((int) $purchase_id);

        $row_inserted = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $line_id), ARRAY_A);
        return $row_inserted ?: [];
    }

    /**
     * Actualiza qty, unit_cost y/o origin_cost de una línea existente. Recalcula
     * line_total y el total de la compra. `$origin_cost === null` deja el costo
     * de origen como está (compatibilidad con llamadas que solo editan qty/costo).
     */
    public static function save_line($line_id, $qty, $unit_cost, $origin_cost = null, $supplier_id = null)
    {
        $line_id = (int) $line_id;
        global $wpdb;
        $t = IEM_Schema::table('purchase_lines');
        $line = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $line_id), ARRAY_A);
        if (!$line) {
            return new WP_Error('iem_not_found', 'Línea no encontrada.');
        }
        $purchase = self::get((int) $line['purchase_id']);
        if (!$purchase) {
            return new WP_Error('iem_not_found', 'Compra no encontrada.');
        }
        if ($purchase['status'] !== 'draft') {
            return new WP_Error('iem_locked', 'La compra ya fue recibida.');
        }
        $qty       = (int) $qty;
        $unit_cost = (float) $unit_cost;
        if ($qty <= 0)        return new WP_Error('iem_invalid_qty',  'La cantidad debe ser mayor a 0.');
        if ($unit_cost < 0)   return new WP_Error('iem_invalid_cost', 'El costo no puede ser negativo.');

        $line_total = round($qty * $unit_cost, 2);

        $data    = ['qty' => $qty, 'unit_cost' => $unit_cost, 'line_total' => $line_total];
        $formats = ['%d', '%f', '%f'];
        if ($origin_cost !== null) {
            $origin_cost = (float) $origin_cost;
            if ($origin_cost < 0) {
                return new WP_Error('iem_invalid_origin_cost', 'El costo de origen no puede ser negativo.');
            }
            $data['origin_cost'] = $origin_cost;
            $formats[]           = '%f';
        }
        if ($supplier_id !== null) {
            $supplier_id = (int) $supplier_id;
            $sup_err = self::validate_line_supplier($supplier_id);
            if (is_wp_error($sup_err)) return $sup_err;
            $data['supplier_id'] = $supplier_id;
            $formats[]           = '%d';
        }

        $ok = $wpdb->update($t, $data, ['id' => $line_id], $formats, ['%d']);
        if ($ok === false) {
            return new WP_Error('iem_db_update', 'No se pudo actualizar la línea.');
        }
        $new_total = self::recalc_total((int) $line['purchase_id']);
        return [
            'line_total'     => $line_total,
            'purchase_total' => $new_total,
        ];
    }

    public static function delete_line($line_id)
    {
        $line_id = (int) $line_id;
        global $wpdb;
        $t = IEM_Schema::table('purchase_lines');
        $line = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $line_id), ARRAY_A);
        if (!$line) {
            return new WP_Error('iem_not_found', 'Línea no encontrada.');
        }
        $purchase = self::get((int) $line['purchase_id']);
        if (!$purchase) {
            return new WP_Error('iem_not_found', 'Compra no encontrada.');
        }
        if ($purchase['status'] !== 'draft') {
            return new WP_Error('iem_locked', 'La compra ya fue recibida.');
        }
        $wpdb->delete($t, ['id' => $line_id], ['%d']);
        $new_total = self::recalc_total((int) $line['purchase_id']);
        return ['purchase_total' => $new_total];
    }

    /** Recalcula header.total = Σ purchase_lines.line_total. */
    public static function recalc_total($purchase_id)
    {
        global $wpdb;
        $tl = IEM_Schema::table('purchase_lines');
        $tp = IEM_Schema::table('purchases');
        $sum = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(line_total),0) FROM $tl WHERE purchase_id = %d",
            (int) $purchase_id
        ));
        $sum = round($sum, 2);
        $wpdb->update($tp,
            ['total' => $sum, 'updated_at' => current_time('mysql')],
            ['id' => (int) $purchase_id],
            ['%f', '%s'],
            ['%d']
        );
        return $sum;
    }

    // ── Recepción atómica ─────────────────────────────────────────────────

    /**
     * Recibe una compra. Atómico:
     *   - Para cada línea variable, exige una variación destino en
     *     `$variation_map[line_id]`. Para simples, ignora el mapa.
     *   - Verifica TODAS las líneas (target item existe, gestiona stock, y si
     *     es variable que la variación pertenezca al padre) antes de tocar nada.
     *   - START TRANSACTION; por cada línea: increase stock + update cost del
     *     target item; persiste `received_variation_id`; header: status=received,
     *     fechas/user. COMMIT.
     *   - Si algún paso falla → ROLLBACK total.
     *
     * NOTA: `wc_update_product_stock` y `update_post_meta` usan MyISAM-safe
     * primitives sobre tablas de WP que normalmente son InnoDB. Si la BD
     * fuera MyISAM, el rollback no aplicaría a wp_postmeta/wp_posts y habría
     * inconsistencia. En la práctica WP siempre usa InnoDB. Asumimos InnoDB.
     *
     * HOOK (3.27+): al confirmarse la recepción (tras COMMIT) se dispara
     *
     *     do_action('iem_purchase_received', int $id, float $total, array $purchase)
     *
     * donde `$total` es el monto de la compra (Σ líneas, en Bs) y `$purchase`
     * es la fila completa ya en estado 'received'. Pensado para integraciones
     * externas como el control de efectivo: en este negocio toda compra se paga
     * en efectivo al recibir, así que este evento = salida de caja por `$total`.
     * Se emite una sola vez por compra (la transición a 'received' es terminal).
     *
     * @param int      $id
     * @param string   $received_date  Y-m-d.
     * @param array    $receipts_map   line_id => [variation_id => qty, ...].
     *                 Para simples se ignora (entra todo al producto). Para
     *                 variables, la suma de qty debe igualar el qty de la línea.
     * @param bool     $affect_inventory  Si false (2.6+), la compra se marca como
     *                 recibida SIN tocar nada del producto: no suma stock, no
     *                 escribe kardex, no actualiza costo ni registra recepciones.
     *                 Para compras legadas cuyo stock ya se ajustó a mano antes de
     *                 subir el plugin. Se guarda `inventory_affected=0`.
     */
    public static function receive($id, $received_date = null, array $receipts_map = [], $affect_inventory = true)
    {
        $row = self::get((int) $id);
        if (!$row) {
            return new WP_Error('iem_not_found', 'Compra no encontrada.');
        }
        if ($row['status'] !== 'draft') {
            return new WP_Error('iem_already_received', 'La compra ya fue recibida.');
        }
        $lines = self::get_lines((int) $id);
        if (empty($lines)) {
            return new WP_Error('iem_no_lines', 'La compra no tiene líneas para recibir.');
        }

        // Fase 1: validar TODO antes de tocar nada. Por línea armamos una lista de
        // recepciones [ ['product'=>WC, 'variation_id'=>int, 'qty'=>int], ... ]:
        //   - Padre simple → 1 recepción al propio producto, qty = qty de la línea.
        //   - Padre variable → 1+ recepciones por variación elegida; la SUMA de
        //     qty debe igualar el qty de la línea (sin stock comprado sin recibir).
        // Si NO se afecta el inventario, no hay destino que validar: se omite la fase.
        $plan = []; // line_id => array de recepciones
        if ($affect_inventory) foreach ($lines as $line) {
            $line_id   = (int) $line['id'];
            $line_qty  = (int) $line['qty'];
            $parent_id = (int) $line['item_id'];
            $parent    = wc_get_product($parent_id);
            if (!$parent) {
                return new WP_Error('iem_line_no_product',
                    sprintf('Producto padre no encontrado para línea %s.', $line['sku']));
            }

            if ($parent->is_type('simple')) {
                if (!$parent->managing_stock()) {
                    return new WP_Error('iem_line_no_stock_mgmt',
                        sprintf('El producto %s no gestiona stock; actívalo antes de recibir.', $line['sku']));
                }
                $plan[$line_id] = [[
                    'product'      => $parent,
                    'variation_id' => $parent_id, // el simple es su propio destino
                    'qty'          => $line_qty,
                ]];
            } elseif ($parent->is_type('variable')) {
                $entries = isset($receipts_map[$line_id]) && is_array($receipts_map[$line_id])
                    ? $receipts_map[$line_id] : [];
                $receipts = [];
                $sum = 0;
                foreach ($entries as $vid => $qty) {
                    $vid = (int) $vid;
                    $qty = (int) $qty;
                    if ($vid <= 0 || $qty <= 0) {
                        continue; // variación no asignada
                    }
                    $variation = wc_get_product($vid);
                    if (!$variation || !$variation->is_type('variation') || (int) $variation->get_parent_id() !== $parent_id) {
                        return new WP_Error('iem_line_variation_mismatch',
                            sprintf('Una variación elegida no pertenece al producto %s.', $line['sku']));
                    }
                    if (!$variation->managing_stock()) {
                        return new WP_Error('iem_line_no_stock_mgmt',
                            sprintf('Una variación de %s no gestiona stock; actívalo antes de recibir.', $line['sku']));
                    }
                    $receipts[] = [
                        'product'      => $variation,
                        'variation_id' => $vid,
                        'qty'          => $qty,
                    ];
                    $sum += $qty;
                }
                if (empty($receipts)) {
                    return new WP_Error('iem_line_variation_required',
                        sprintf('Falta asignar la(s) variación(es) destino para la línea %s.', $line['sku']));
                }
                if ($sum !== $line_qty) {
                    return new WP_Error('iem_line_qty_mismatch',
                        sprintf('En %s, la suma por variación (%d) debe igualar la cantidad comprada (%d).',
                            $line['sku'], $sum, $line_qty));
                }
                $plan[$line_id] = $receipts;
            } else {
                return new WP_Error('iem_line_unsupported_type',
                    sprintf('Tipo de producto no soportado para línea %s.', $line['sku']));
            }
        }

        // Fechas: received_date editable; por defecto hoy. Debe ser >= purchase_date.
        $received_date = trim((string) $received_date);
        if ($received_date === '') {
            $received_date = wp_date('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $received_date)) {
            return new WP_Error('iem_invalid_date', 'Fecha de recepción inválida.');
        }
        if ($received_date < $row['purchase_date']) {
            return new WP_Error('iem_invalid_date',
                'La fecha de recepción no puede ser anterior a la fecha de la compra.');
        }
        if ($received_date > wp_date('Y-m-d')) {
            return new WP_Error('iem_invalid_date', 'La fecha de recepción no puede ser futura.');
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            // movement_date del kardex: usar el received_date + hora actual.
            // Así si la compra se recibió "ayer" retroactivamente, los movimientos
            // del kardex quedan en la fecha real declarada por el operador.
            $mov_date = $received_date . ' ' . wp_date('H:i:s');

            $lines_table    = IEM_Schema::table('purchase_lines');
            $receipts_table = IEM_Schema::table('purchase_line_receipts');
            $now            = current_time('mysql');

            // Cuando NO se afecta el inventario (compra legada con stock ya ajustado
            // a mano) se omite todo el reparto: nada de stock, kardex, costo ni
            // recepciones. La compra solo cambia de estado más abajo.
            if ($affect_inventory) foreach ($lines as $line) {
                $line_id     = (int) $line['id'];
                $receipts    = $plan[$line_id];
                $cost        = (float) $line['unit_cost'];
                // Costo de origen (Valor Exw USD) de ESTA compra (snapshot en kardex,
                // NULL si no se capturó). Es independiente del unit_cost en Bs.
                $line_origin = (float) ($line['origin_cost'] ?? 0);
                $kx_origin   = $line_origin > 0 ? $line_origin : null;

                foreach ($receipts as $rcp) {
                    $p   = $rcp['product'];
                    $qty = (int) $rcp['qty'];

                    // 3.20+: la sucursal del movimiento la define la VARIACIÓN
                    // recibida (su `pa_sucursal`), no el header. Mantiene los
                    // reportes por sucursal correctos en compras mixtas.
                    $kx_sucursal = '';
                    if (class_exists('IEM_Sucursales')) {
                        $kx_sucursal = (string) IEM_Sucursales::resolve_product_sucursal_slug($p);
                    }
                    if ($kx_sucursal === '') {
                        $kx_sucursal = (string) $row['sucursal_slug']; // fallback: header.
                    }

                    $r = IEM_Kardex::record([
                        'item_id'       => (int) $p->get_id(),
                        'type'          => IEM_Kardex::TYPE_PURCHASE,
                        'direction'     => 'I',
                        'qty'           => $qty,
                        'unit_cost'     => $cost,
                        'origin_cost'   => $kx_origin,
                        'sucursal_slug' => $kx_sucursal,
                        'ref_table'     => 'purchases',
                        'ref_id'        => (int) $id,
                        'ref_code'      => (string) $row['code'],
                        'movement_date' => $mov_date,
                        'update_wc'     => true,
                    ]);
                    if (is_wp_error($r)) {
                        throw new Exception(sprintf('Error sumando stock de %s: %s',
                            $line['sku'], $r->get_error_message()));
                    }
                    // Actualiza costo del item destino (variación o simple).
                    update_post_meta((int) $p->get_id(), '_alg_wc_cog_cost', (string) $cost);

                    // Registra la recepción (qué variación recibió cuánto).
                    $ins = $wpdb->insert($receipts_table, [
                        'purchase_id'   => (int) $id,
                        'line_id'       => $line_id,
                        'variation_id'  => (int) $p->get_id(),
                        'sucursal_slug' => $kx_sucursal,
                        'qty'           => $qty,
                        'created_at'    => $now,
                    ], ['%d','%d','%d','%s','%d','%s']);
                    if (!$ins) {
                        throw new Exception(sprintf('Error guardando la recepción de %s.', $line['sku']));
                    }
                }

                // 1.9+: vuelca el costo de origen (Valor Exw USD) de la línea al
                // meta `costo_de_origen` del producto PADRE. Solo si se capturó (>0).
                if ($line_origin > 0) {
                    update_post_meta((int) $line['item_id'], 'costo_de_origen', (string) $line_origin);
                }

                // Vestigio: `received_variation_id` queda con la variación única si
                // la línea recibió en una sola (la fuente canónica son los receipts).
                $single_vid = (count($receipts) === 1 && (int) $line['item_id'] !== (int) $receipts[0]['variation_id'])
                    ? (int) $receipts[0]['variation_id']
                    : 0;
                $upd = $wpdb->update($lines_table,
                    ['received_variation_id' => $single_vid],
                    ['id' => $line_id],
                    ['%d'], ['%d']
                );
                if ($upd === false) {
                    throw new Exception(sprintf('Error actualizando la línea %s.', $line['sku']));
                }
            }

            $tp = IEM_Schema::table('purchases');
            $ok = $wpdb->update($tp, [
                'status'             => 'received',
                'received_date'      => $received_date,
                'received_at'        => current_time('mysql'),
                'received_by'        => (int) get_current_user_id(),
                'inventory_affected' => $affect_inventory ? 1 : 0,
                'updated_at'         => current_time('mysql'),
            ], ['id' => (int) $id],
            ['%s','%s','%s','%d','%d','%s'], ['%d']);
            if ($ok === false) {
                throw new Exception('Error actualizando cabecera de compra.');
            }

            $wpdb->query('COMMIT');

            // Compra confirmada en BD. Emitimos el evento DESPUÉS del COMMIT
            // para que cualquier consumidor (p. ej. control de caja) lea un
            // estado consistente. Releemos la fila para entregar el snapshot
            // final ya en 'received' con fechas/usuario actualizados.
            $fresh = self::get((int) $id) ?: $row;
            do_action('iem_purchase_received', (int) $id, (float) $fresh['total'], $fresh);

            return true;

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('iem_receive_failed', $e->getMessage());
        }
    }

    // ── Helpers internos ──────────────────────────────────────────────────

    /** Valida el proveedor de una línea (2.1+): obligatorio y existente. */
    private static function validate_line_supplier($supplier_id)
    {
        $supplier_id = (int) $supplier_id;
        if ($supplier_id <= 0) {
            return new WP_Error('iem_line_supplier_required', 'Selecciona el proveedor del producto.');
        }
        if (!IEM_Suppliers::get($supplier_id)) {
            return new WP_Error('iem_line_supplier_invalid', 'Proveedor de la línea inválido.');
        }
        return true;
    }

    /**
     * Sanitiza/valida los argumentos del header. Devuelve array o WP_Error.
     * Campos: code, supplier_id, sucursal_slug, purchase_date, invoice_number, notes.
     */
    private static function sanitize_header_args(array $args)
    {
        $args = array_merge([
            'code'            => '',
            'supplier_id'     => 0,
            'sucursal_slug'   => '',
            'purchase_date'   => '',
            'invoice_number'  => '',
            'package_count'   => 0,
            'gross_weight_kg' => 0,
            'cbm_m3'          => 0,
            'shipping_via'    => '',
            'tracking_number' => '',
            'eta_date'        => '',
            'notes'           => '',
        ], $args);

        $code = sanitize_text_field((string) $args['code']);
        if ($code !== '' && !preg_match('/^[A-Za-z0-9_\-\.]{1,50}$/', $code)) {
            return new WP_Error('iem_invalid_code',
                'El código solo admite letras, dígitos, guiones, guiones bajos y puntos (máx 50).');
        }

        // 2.1+: el proveedor de la cabecera es OPCIONAL (proveedor por defecto que
        // pre-rellena las líneas). El proveedor real para reportes vive por línea.
        $supplier_id = (int) $args['supplier_id'];
        if ($supplier_id < 0) {
            $supplier_id = 0;
        }
        if ($supplier_id > 0 && !IEM_Suppliers::get($supplier_id)) {
            return new WP_Error('iem_supplier_not_found', 'Proveedor inválido.');
        }

        // Sucursal en el header pasa a ser SUGERENCIA (3.20+): se usa solo
        // para autoseleccionar variaciones en el form de recepción. El destino
        // real del stock es cada variación elegida por línea, y el kardex
        // toma la sucursal de esa variación, no del header. Por eso ya no es
        // obligatoria; vacío es válido.
        $sucursal_slug = sanitize_text_field((string) $args['sucursal_slug']);

        $purchase_date = sanitize_text_field((string) $args['purchase_date']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchase_date)) {
            return new WP_Error('iem_invalid_date', 'La fecha de la compra es inválida.');
        }

        // 2.3+: datos logísticos de la importación (no negativos). La vía admite
        // vacío (sin definir), 'aerea' o 'maritima'.
        $package_count   = max(0, (int) $args['package_count']);
        $gross_weight_kg = max(0, round((float) $args['gross_weight_kg'], 2));
        $cbm_m3          = max(0, round((float) $args['cbm_m3'], 4));
        $shipping_via    = sanitize_key((string) $args['shipping_via']);
        if (!in_array($shipping_via, ['', 'aerea', 'maritima'], true)) {
            $shipping_via = '';
        }

        // 2.7+: Nº de tracking (alfanumérico libre, máx 100) y ETA (fecha de arribo,
        // opcional). ETA vacío = sin definir; si viene, debe ser una fecha válida.
        $tracking_number = sanitize_text_field((string) $args['tracking_number']);
        if (mb_strlen($tracking_number) > 100) {
            $tracking_number = mb_substr($tracking_number, 0, 100);
        }
        $eta_date = sanitize_text_field((string) $args['eta_date']);
        if ($eta_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eta_date)) {
            return new WP_Error('iem_invalid_eta', 'La fecha de arribo (ETA) es inválida.');
        }

        return [
            'code'            => $code,
            'supplier_id'     => $supplier_id,
            'sucursal_slug'   => $sucursal_slug,
            'purchase_date'   => $purchase_date,
            'invoice_number'  => sanitize_text_field((string) $args['invoice_number']),
            'package_count'   => $package_count,
            'gross_weight_kg' => $gross_weight_kg,
            'cbm_m3'          => $cbm_m3,
            'shipping_via'    => $shipping_via,
            'tracking_number' => $tracking_number,
            'eta_date'        => $eta_date,
            'notes'           => wp_kses_post((string) $args['notes']),
        ];
    }
}
