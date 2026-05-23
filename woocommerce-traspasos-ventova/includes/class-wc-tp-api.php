<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API pública del plugin de Traspasos.
 *
 * Punto único de consulta/escritura para integraciones externas
 * (ej. plugin DEMV). Mantiene SQL y reglas de negocio encapsuladas.
 */
class WC_TP_API
{
    /**
     * Consulta paginada del historial con filtros y agregaciones.
     *
     * Filtros aceptados (todos opcionales):
     *   - year (int)
     *   - month (int 1-12)
     *   - months (int[]) — alternativa multi-valor; tiene prioridad si está
     *   - day (int 1-31)
     *   - date_from (Y-m-d)
     *   - date_to   (Y-m-d)
     *   - origen (slug sucursal) — usar este al filtrar por sucursal
     *                              desde DEMV (el pago de envío sale del origen)
     *   - destino (slug sucursal)
     *   - estado (string)
     *   - metodo_envio (string)  — uno de WC_TP_Config::METODOS_ENVIO
     *   - guia (string LIKE)
     *   - sin_pago_envio (bool)  → fecha_pago_envio IS NULL
     *   - con_pago_envio (bool)  → fecha_pago_envio IS NOT NULL
     *   - sin_costo_envio (bool) → costo_envio IS NULL OR 0
     *   - per_page (int, default 50)
     *   - page (int, default 1)
     *   - orderby ('date_created'|'id'|'estado', default 'date_created')
     *   - order ('ASC'|'DESC', default 'DESC')
     *
     * @return array{rows:array,total:int,sum_costo_envio:float,pages:int}
     */
    public static function query(array $filters = [])
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_tp_history';

        $where = ['1=1'];
        $args  = [];

        if (!empty($filters['year'])) {
            $where[] = 'YEAR(date_created) = %d';
            $args[]  = (int) $filters['year'];
        }
        if (!empty($filters['months']) && is_array($filters['months'])) {
            $months = array_values(array_unique(array_filter(
                array_map('intval', $filters['months']),
                function ($m) { return $m >= 1 && $m <= 12; }
            )));
            if (!empty($months)) {
                $where[] = 'MONTH(date_created) IN (' . implode(',', array_fill(0, count($months), '%d')) . ')';
                foreach ($months as $m) { $args[] = $m; }
            }
        } elseif (!empty($filters['month'])) {
            $where[] = 'MONTH(date_created) = %d';
            $args[]  = (int) $filters['month'];
        }
        if (!empty($filters['day'])) {
            $where[] = 'DAY(date_created) = %d';
            $args[]  = (int) $filters['day'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(date_created) >= %s';
            $args[]  = sanitize_text_field($filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(date_created) <= %s';
            $args[]  = sanitize_text_field($filters['date_to']);
        }
        if (!empty($filters['origen'])) {
            $where[] = 'origen = %s';
            $args[]  = sanitize_text_field($filters['origen']);
        }
        if (!empty($filters['destino'])) {
            $where[] = 'destino = %s';
            $args[]  = sanitize_text_field($filters['destino']);
        }
        if (!empty($filters['estado'])) {
            $where[] = 'estado = %s';
            $args[]  = sanitize_text_field($filters['estado']);
        }
        if (!empty($filters['metodo_envio'])) {
            $where[] = 'metodo_envio = %s';
            $args[]  = sanitize_text_field($filters['metodo_envio']);
        }
        if (!empty($filters['guia'])) {
            $where[] = 'guia LIKE %s';
            $args[]  = '%' . $wpdb->esc_like(sanitize_text_field($filters['guia'])) . '%';
        }
        if (!empty($filters['sin_pago_envio'])) {
            $where[] = 'fecha_pago_envio IS NULL';
        }
        if (!empty($filters['con_pago_envio'])) {
            $where[] = 'fecha_pago_envio IS NOT NULL';
        }
        if (!empty($filters['sin_costo_envio'])) {
            $where[] = '(costo_envio IS NULL OR costo_envio = 0)';
        }

        $where_sql = implode(' AND ', $where);

        // Orden
        $orderby_allow = ['date_created', 'id', 'estado'];
        $orderby = $filters['orderby'] ?? 'date_created';
        if (!in_array($orderby, $orderby_allow, true)) {
            $orderby = 'date_created';
        }
        $order = (strtoupper($filters['order'] ?? 'DESC') === 'ASC') ? 'ASC' : 'DESC';

        // Paginación
        $per_page = max(1, (int) ($filters['per_page'] ?? 50));
        $page     = max(1, (int) ($filters['page'] ?? 1));
        $offset   = ($page - 1) * $per_page;

        // Total + suma
        $count_sql    = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        $sum_sql      = "SELECT COALESCE(SUM(costo_envio),0) FROM $table WHERE $where_sql";
        $sum_ibex_sql = "SELECT COALESCE(SUM(costo_envio),0) FROM $table WHERE $where_sql AND metodo_envio = %s";

        $total = $args
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $args))
            : (int) $wpdb->get_var($count_sql);

        $sum = $args
            ? (float) $wpdb->get_var($wpdb->prepare($sum_sql, $args))
            : (float) $wpdb->get_var($sum_sql);

        // Suma costo_envio solo para traspasos con metodo_envio='IBEX' (mismo WHERE).
        $sum_ibex = (float) $wpdb->get_var(
            $wpdb->prepare($sum_ibex_sql, ...array_merge($args, ['IBEX']))
        );

        // Rows
        $rows_sql = "SELECT * FROM $table WHERE $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $prepared_rows = empty($args)
            ? $wpdb->prepare($rows_sql, $per_page, $offset)
            : $wpdb->prepare($rows_sql, ...array_merge($args, [$per_page, $offset]));
        $rows = $wpdb->get_results($prepared_rows, ARRAY_A);

        // Normalización de filas
        $rows = array_map([__CLASS__, '_normalize_row'], $rows ?: []);

        return [
            'rows'                 => $rows,
            'total'                => $total,
            'sum_costo_envio'      => round($sum, 2),
            'sum_costo_envio_ibex' => round($sum_ibex, 2),
            'pages'                => (int) ceil($total / $per_page),
        ];
    }

    /**
     * Devuelve una fila individual normalizada o null.
     */
    public static function get($id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_tp_history';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $id),
            ARRAY_A
        );
        return $row ? self::_normalize_row($row) : null;
    }

    /**
     * Define o actualiza el costo de envío de un traspaso.
     *
     * @param int   $id
     * @param float $valor  Monto en bolivianos. 0 o NULL borra el valor.
     * @return true|WP_Error
     */
    public static function set_costo_envio($id, $valor)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_tp_history';

        $id = (int) $id;
        if ($id <= 0) {
            return new WP_Error('wc_tp_invalid_id', 'ID de traspaso inválido');
        }

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $id));
        if (!$exists) {
            return new WP_Error('wc_tp_not_found', 'Traspaso no encontrado');
        }

        if ($valor === null || $valor === '' || (float) $valor <= 0) {
            $stored = null;
        } else {
            $stored = round((float) $valor, 2);
        }

        $result = $wpdb->update(
            $table,
            ['costo_envio' => $stored],
            ['id' => $id],
            [$stored === null ? '%s' : '%f'],
            ['%d']
        );

        if ($result === false) {
            return new WP_Error('wc_tp_db_error', $wpdb->last_error ?: 'Error al actualizar');
        }

        return true;
    }

    /**
     * Define o actualiza el método de envío de un traspaso.
     * Solo acepta valores de WC_TP_Config::METODOS_ENVIO; vacío/null lo borra.
     *
     * @param int    $id
     * @param string $valor
     * @return true|WP_Error
     */
    public static function set_metodo_envio($id, $valor)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_tp_history';

        $id = (int) $id;
        if ($id <= 0) {
            return new WP_Error('wc_tp_invalid_id', 'ID de traspaso inválido');
        }

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $id));
        if (!$exists) {
            return new WP_Error('wc_tp_not_found', 'Traspaso no encontrado');
        }

        $valor = is_string($valor) ? trim($valor) : '';
        if ($valor === '') {
            $stored = null;
        } elseif (!WC_TP_Config::is_metodo_envio_valido($valor)) {
            return new WP_Error(
                'wc_tp_invalid_metodo',
                'Método de envío no permitido. Válidos: ' . implode(', ', WC_TP_Config::get_metodos_envio())
            );
        } else {
            $stored = $valor;
        }

        $result = $wpdb->update(
            $table,
            ['metodo_envio' => $stored],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        if ($result === false) {
            return new WP_Error('wc_tp_db_error', $wpdb->last_error ?: 'Error al actualizar');
        }

        return true;
    }

    /**
     * Marca pago de envío para uno o varios traspasos.
     * Exige que cada traspaso tenga costo_envio > 0 y metodo_envio definido.
     *
     * Comportamiento "todo o nada": si cualquiera de los IDs falla validación,
     * no se escribe ninguno y se devuelven todos los errores. Solo si todos
     * son válidos se aplican los UPDATE (envueltos en transacción).
     *
     * @param int[]  $ids
     * @param string $fecha (Y-m-d). Si vacío, usa fecha actual.
     * @return array{ok:int[],errors:array<int,string>}
     */
    public static function mark_pago_envio(array $ids, $fecha = '')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_tp_history';

        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            return ['ok' => [], 'errors' => ['*' => 'No se enviaron IDs']];
        }

        $fecha = $fecha ? sanitize_text_field($fecha) : current_time('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return ['ok' => [], 'errors' => ['*' => 'Fecha inválida']];
        }

        // Pasada 1: validar todos antes de escribir.
        $errors = [];
        foreach ($ids as $id) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, costo_envio, metodo_envio, fecha_pago_envio FROM $table WHERE id = %d",
                    $id
                ),
                ARRAY_A
            );
            if (!$row) {
                $errors[$id] = 'No existe';
                continue;
            }
            if ($row['costo_envio'] === null || (float) $row['costo_envio'] <= 0) {
                $errors[$id] = 'Sin costo de envío definido';
                continue;
            }
            if (empty($row['metodo_envio'])) {
                $errors[$id] = 'Sin método de envío definido';
                continue;
            }
            if (!empty($row['fecha_pago_envio'])) {
                $errors[$id] = 'Ya tiene pago registrado';
                continue;
            }
        }

        if (!empty($errors)) {
            return ['ok' => [], 'errors' => $errors];
        }

        // Pasada 2: aplicar todos en transacción.
        $wpdb->query('START TRANSACTION');
        $ok = [];
        foreach ($ids as $id) {
            $result = $wpdb->update(
                $table,
                ['fecha_pago_envio' => $fecha],
                ['id' => $id],
                ['%s'],
                ['%d']
            );
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                return [
                    'ok'     => [],
                    'errors' => ['*' => 'Error DB durante actualización: ' . ($wpdb->last_error ?: 'desconocido')],
                ];
            }
            $ok[] = $id;
        }
        $wpdb->query('COMMIT');

        return ['ok' => $ok, 'errors' => []];
    }

    /**
     * Sucursales disponibles (slug => nombre).
     */
    public static function get_sucursales()
    {
        return WC_TP_Config::get_sucursales();
    }

    /**
     * Métodos de envío permitidos para traspasos.
     */
    public static function get_metodos_envio()
    {
        return WC_TP_Config::get_metodos_envio();
    }

    // ── Internos ──────────────────────────────────────────────────────────

    private static function _normalize_row(array $row)
    {
        $row['id']               = (int) $row['id'];
        $row['user_id']          = (int) $row['user_id'];
        $row['completed']        = (int) $row['completed'];
        $row['costo_envio']      = isset($row['costo_envio']) && $row['costo_envio'] !== null
            ? (float) $row['costo_envio']
            : null;
        $row['fecha_pago_envio'] = $row['fecha_pago_envio'] ?: null;
        $row['metodo_envio']     = isset($row['metodo_envio']) && $row['metodo_envio'] !== null
            ? (string) $row['metodo_envio']
            : null;
        $row['origen_label']     = WC_TP_Config::get_sucursal_name($row['origen']);
        $row['destino_label']    = WC_TP_Config::get_sucursal_name($row['destino']);
        return $row;
    }
}
