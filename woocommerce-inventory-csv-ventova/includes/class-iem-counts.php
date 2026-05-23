<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CRUD de sesiones de conteo persistido y sus líneas.
 *
 * Reglas:
 *  - Solo una sesión por (period, sucursal_slug) — la UNIQUE KEY lo garantiza.
 *  - Una sesión nueva snapshotea el inventario actual (IEM_Collector) y crea
 *    una línea por producto con counted_qty=NULL.
 *  - Al cerrarse, la sesión queda bloqueada (sin edición de líneas) salvo que
 *    se reabra explícitamente.
 */
class IEM_Counts
{
    public static function current_period()
    {
        return wp_date('Y-m');
    }

    // ── Sesiones ──────────────────────────────────────────────────────────

    public static function get_session($id)
    {
        global $wpdb;
        $t = IEM_Schema::table('count_sessions');
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $t WHERE id=%d", (int) $id),
            ARRAY_A
        ) ?: null;
    }

    public static function get_session_for_period_sucursal($period, $sucursal_slug)
    {
        global $wpdb;
        $t = IEM_Schema::table('count_sessions');
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $t WHERE period=%s AND sucursal_slug=%s", $period, $sucursal_slug),
            ARRAY_A
        ) ?: null;
    }

    public static function list_sessions(array $args = [])
    {
        global $wpdb;
        $t = IEM_Schema::table('count_sessions');
        $where  = ['1=1'];
        $params = [];
        if (!empty($args['period']))        { $where[] = 'period=%s';        $params[] = $args['period']; }
        if (!empty($args['sucursal_slug'])) { $where[] = 'sucursal_slug=%s'; $params[] = $args['sucursal_slug']; }
        if (!empty($args['status']))        { $where[] = 'status=%s';        $params[] = $args['status']; }

        $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC, id DESC";
        $sql = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Crea una sesión nueva snapshoteando el inventario para la sucursal.
     * Si ya existe sesión draft para (period, sucursal) la retorna.
     * Si existe cerrada → error.
     *
     * @return int|WP_Error session_id en éxito.
     */
    public static function create_or_resume($period, $sucursal_slug, $user_id)
    {
        $existing = self::get_session_for_period_sucursal($period, $sucursal_slug);
        if ($existing) {
            if ($existing['status'] === 'closed') {
                return new WP_Error('iem_session_closed',
                    sprintf('Ya existe un conteo CERRADO para %s / %s. Reábrelo desde el histórico antes de continuar.',
                        $period, $sucursal_slug));
            }
            return (int) $existing['id'];
        }

        global $wpdb;
        $sessions = IEM_Schema::table('count_sessions');
        $lines    = IEM_Schema::table('count_lines');
        $now      = current_time('mysql');

        $ok = $wpdb->insert($sessions, [
            'period'        => $period,
            'sucursal_slug' => $sucursal_slug,
            'status'        => 'draft',
            'created_by'    => (int) $user_id,
            'created_at'    => $now,
        ]);
        if (!$ok) {
            return new WP_Error('iem_db_insert', 'No se pudo crear la sesión.');
        }
        $session_id = (int) $wpdb->insert_id;

        // Snapshot del inventario actual para esa sucursal.
        $rows = IEM_Collector::collect($sucursal_slug);
        foreach ($rows as $r) {
            $wpdb->insert($lines, [
                'session_id'      => $session_id,
                'item_id'         => (int) $r['item_id'],
                'parent_id'       => (int) $r['parent_id'],
                'sku'             => (string) $r['sku'],
                'name'            => (string) $r['name'],
                'category'        => (string) $r['category'],
                'sucursal_slug'   => (string) $r['sucursal_slug'],
                'stock_at_count'  => (int) $r['stock'],
                'counted_qty'     => null,
                'status_at_count' => 'Sin conteo',
                'updated_at'      => $now,
            ]);
        }

        return $session_id;
    }

    public static function close($session_id, $user_id)
    {
        global $wpdb;
        $s = self::get_session($session_id);
        if (!$s) {
            return new WP_Error('iem_no_session', 'Sesión no encontrada.');
        }
        if ($s['status'] !== 'draft') {
            return new WP_Error('iem_not_draft', 'La sesión ya está cerrada.');
        }
        $wpdb->update(IEM_Schema::table('count_sessions'), [
            'status'    => 'closed',
            'closed_by' => (int) $user_id,
            'closed_at' => current_time('mysql'),
        ], ['id' => (int) $session_id]);
        return true;
    }

    public static function reopen($session_id)
    {
        global $wpdb;
        $s = self::get_session($session_id);
        if (!$s) {
            return new WP_Error('iem_no_session', 'Sesión no encontrada.');
        }
        if ($s['status'] !== 'closed') {
            return new WP_Error('iem_not_closed', 'La sesión no está cerrada.');
        }
        $wpdb->update(IEM_Schema::table('count_sessions'), [
            'status'    => 'draft',
            'closed_by' => null,
            'closed_at' => null,
        ], ['id' => (int) $session_id]);
        return true;
    }

    public static function delete_session($session_id)
    {
        global $wpdb;
        $wpdb->delete(IEM_Schema::table('count_lines'),    ['session_id' => (int) $session_id]);
        $wpdb->delete(IEM_Schema::table('count_sessions'), ['id' => (int) $session_id]);
        return true;
    }

    // ── Líneas ────────────────────────────────────────────────────────────

    public static function get_lines($session_id)
    {
        global $wpdb;
        $t = IEM_Schema::table('count_lines');
        // Orden v3.7+: Categoría ASC (agrupa secciones de la tienda) y
        // dentro de cada grupo SKU ASC. Vacíos al final en ambos niveles.
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $t WHERE session_id=%d
                 ORDER BY (category = '') ASC, category ASC,
                          (sku = '') ASC, sku ASC, id ASC",
                (int) $session_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Persistir el conteo de una línea, identificada por su ID directo.
     * Retorna ['counted_qty'=>?, 'status'=>?, 'is_extra'=>?].
     *
     * Reemplaza al lookup por item_id (que ya no es único desde v1.3, pues
     * las filas "extra" pueden tener item_id=0 múltiples veces). El cliente
     * debe pasar el `line_id` que viene en el render del template.
     */
    public static function save_line_by_id($session_id, $line_id, $raw_qty)
    {
        global $wpdb;
        $session = self::get_session($session_id);
        if (!$session) {
            return new WP_Error('iem_no_session', 'Sesión no encontrada.');
        }
        if ($session['status'] !== 'draft') {
            return new WP_Error('iem_not_draft', 'La sesión está cerrada.');
        }
        $lines = IEM_Schema::table('count_lines');
        $line  = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $lines WHERE id=%d AND session_id=%d",
            (int) $line_id, (int) $session_id
        ), ARRAY_A);
        if (!$line) {
            return new WP_Error('iem_no_line', 'Línea no encontrada.');
        }

        $raw = trim((string) $raw_qty);
        if ($raw === '') {
            $counted = null;
            $status  = 'Sin conteo';
        } else {
            $counted = (int) $raw;
            // Para filas extra el stock esperado siempre es 0; quedan en
            // "Revisar" salvo que el contador escriba 0 (caso raro: cargó y
            // luego corrigió a 0 — ahí ya es OK con stock esperado=0).
            $status  = ($counted === (int) $line['stock_at_count']) ? 'OK' : 'Revisar';
        }

        $wpdb->update($lines, [
            'counted_qty'     => $counted,
            'status_at_count' => $status,
            'updated_at'      => current_time('mysql'),
        ], ['id' => (int) $line['id']]);

        return [
            'counted_qty' => $counted,
            'status'      => $status,
            'is_extra'    => (int) $line['is_extra'],
        ];
    }

    /**
     * Agrega una "fila extra" a una sesión draft. Estas filas representan
     * productos encontrados físicamente que no estaban en el snapshot
     * original (errores de despacho, productos sin catálogo, etc.).
     *
     * Reglas:
     *  - Texto libre: ni el SKU ni el item_id se validan contra WC. Quedan
     *    como referencia/nota para que el admin las cuadre después.
     *  - El "stock esperado" se persiste como 0 → cualquier counted_qty > 0
     *    naturalmente da estado "Revisar".
     *  - Notas opcionales para que el contador documente contexto
     *    ("estaba en estante de SCZ pero etiqueta dice CBBA").
     *
     * @return array|WP_Error La fila insertada, lista para render.
     */
    public static function add_extra_line($session_id, array $args)
    {
        $session = self::get_session($session_id);
        if (!$session) {
            return new WP_Error('iem_no_session', 'Sesión no encontrada.');
        }
        if ($session['status'] !== 'draft') {
            return new WP_Error('iem_not_draft', 'La sesión está cerrada.');
        }

        $name  = trim((string) ($args['name']  ?? ''));
        $sku   = trim((string) ($args['sku']   ?? ''));
        $notes = trim((string) ($args['notes'] ?? ''));
        $qty_raw = isset($args['qty']) ? trim((string) $args['qty']) : '';

        if ($name === '') {
            return new WP_Error('iem_invalid', 'El nombre del producto es obligatorio.');
        }
        if ($qty_raw === '' || !is_numeric($qty_raw) || (int) $qty_raw < 0) {
            return new WP_Error('iem_invalid', 'La cantidad debe ser un número ≥ 0.');
        }
        $counted = (int) $qty_raw;
        $status  = $counted === 0 ? 'OK' : 'Revisar';

        global $wpdb;
        $lines = IEM_Schema::table('count_lines');
        $now   = current_time('mysql');

        $ok = $wpdb->insert($lines, [
            'session_id'      => (int) $session_id,
            'item_id'         => 0,
            'parent_id'       => 0,
            'sku'             => $sku,
            'name'            => $name,
            'category'        => '',
            'sucursal_slug'   => (string) $session['sucursal_slug'],
            'stock_at_count'  => 0,
            'counted_qty'     => $counted,
            'status_at_count' => $status,
            'is_extra'        => 1,
            'notes'           => $notes,
            'updated_at'      => $now,
        ]);
        if (!$ok) {
            return new WP_Error('iem_db_insert', 'No se pudo guardar la fila extra.');
        }
        $id = (int) $wpdb->insert_id;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $lines WHERE id=%d", $id
        ), ARRAY_A);
    }

    /**
     * Elimina una fila extra (is_extra=1) de una sesión draft. Las filas del
     * snapshot original NO se pueden eliminar — solo cambiar su counted_qty.
     */
    public static function delete_extra_line($session_id, $line_id)
    {
        $session = self::get_session($session_id);
        if (!$session) {
            return new WP_Error('iem_no_session', 'Sesión no encontrada.');
        }
        if ($session['status'] !== 'draft') {
            return new WP_Error('iem_not_draft', 'La sesión está cerrada.');
        }
        global $wpdb;
        $lines = IEM_Schema::table('count_lines');
        $line  = $wpdb->get_row($wpdb->prepare(
            "SELECT id, is_extra FROM $lines WHERE id=%d AND session_id=%d",
            (int) $line_id, (int) $session_id
        ), ARRAY_A);
        if (!$line) {
            return new WP_Error('iem_no_line', 'Línea no encontrada.');
        }
        if ((int) $line['is_extra'] !== 1) {
            return new WP_Error('iem_not_extra',
                'Solo se pueden eliminar filas adicionales (extra). Las del listado original quedan fijas.');
        }
        $wpdb->delete($lines, ['id' => (int) $line['id']], ['%d']);
        return true;
    }

    /**
     * Resumen de progreso de una sesión: contados / total / OK / revisar.
     */
    public static function progress($session_id)
    {
        global $wpdb;
        $t = IEM_Schema::table('count_lines');
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN counted_qty IS NOT NULL THEN 1 ELSE 0 END) AS contados,
                SUM(CASE WHEN status_at_count='OK' THEN 1 ELSE 0 END) AS ok,
                SUM(CASE WHEN status_at_count='Revisar' THEN 1 ELSE 0 END) AS revisar
            FROM $t WHERE session_id=%d
        ", (int) $session_id), ARRAY_A);
        return [
            'total'    => (int) ($row['total']    ?? 0),
            'contados' => (int) ($row['contados'] ?? 0),
            'ok'       => (int) ($row['ok']       ?? 0),
            'revisar'  => (int) ($row['revisar']  ?? 0),
        ];
    }
}
