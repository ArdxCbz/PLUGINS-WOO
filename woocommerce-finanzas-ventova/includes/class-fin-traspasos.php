<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * COSTO DE ENVÍO IBEX DE LOS TRASPASOS (v2.20).
 *
 * IBEX no solo cobra el envío de los PEDIDOS: también cobra los TRASPASOS de
 * stock entre sucursales. Ese costo vive en el plugin de Traspasos (tabla
 * `wc_tp_history`, expuesta por `WC_TP_API`) y hasta 2.19 no llegaba a Finanzas:
 * el egreso mensual de IBEX quedaba SUBVALUADO por el monto de los traspasos.
 *
 * UN SOLO EGRESO (2.21): pedidos y traspasos de un mes·sucursal se pagan con UN
 * movimiento — es una sola factura de IBEX y una sola salida de plata. Esta
 * clase aporta la parte de traspasos de ese total; `FIN_Orders` la de pedidos.
 *
 * LA SUCURSAL ES EL ORIGEN. Lo define el propio plugin de Traspasos: "usar este
 * [origen] al filtrar por sucursal desde DEMV (el pago de envío sale del
 * origen)". El destino no paga.
 *
 * EL MES ES EL DE CREACIÓN del traspaso (`date_created`), igual que los pedidos:
 * es el mes que devenga el costo, y por tanto el que el Estado de Resultados
 * debe cargar (aunque el pago se haga después). `fecha_pago_envio` (la marca de
 * pagado de la caja de DEMV) NO se usa para agrupar: es el registro operativo del
 * otro plugin, no el hecho contable.
 *
 * UN TRASPASO SIN COSTO CARGADO ES UN PENDIENTE y bloquea el registro del
 * mes·sucursal, con la misma lógica que un pedido sin costo: si se paga sin él,
 * se paga de menos y no queda rastro de lo que falta. Se carga el costo en la
 * caja de Traspasos de DEMV.
 *
 * DEPENDENCIA OPCIONAL: si el plugin de Traspasos no está activo, `available()`
 * es false y el pago sale solo con los pedidos (Finanzas sigue funcionando).
 */
class FIN_Traspasos
{
    /** Único método de envío de traspasos que cobra IBEX. */
    const METODO = 'IBEX';

    /** Filas por página al recorrer el historial (son filas de tabla, no objetos). */
    const PAGE_SIZE = 200;

    /** Tope de páginas: cinturón de seguridad contra un rango absurdo. */
    const MAX_PAGES = 50;

    // ── Disponibilidad ───────────────────────────────────────────────────────

    /** ¿Está activo el plugin de Traspasos (con su API pública)? */
    public static function available()
    {
        return class_exists('WC_TP_API') && method_exists('WC_TP_API', 'query');
    }

    // ── Lectura del historial de traspasos ───────────────────────────────────

    /**
     * Traspasos IBEX creados en el rango. Devuelve filas crudas de WC_TP_API
     * (paginando hasta agotarlas), no objetos: no hay riesgo de memoria.
     *
     * @return array<int,array> filas normalizadas de WC_TP_API
     */
    private static function rows($from, $to)
    {
        if (!self::available()) {
            return [];
        }

        $out  = [];
        $page = 1;
        do {
            $res = WC_TP_API::query([
                'date_from'    => (string) $from,
                'date_to'      => (string) $to,
                'metodo_envio' => self::METODO,
                'orderby'      => 'id',
                'order'        => 'ASC',
                'per_page'     => self::PAGE_SIZE,
                'page'         => $page,
            ]);
            $rows = isset($res['rows']) && is_array($res['rows']) ? $res['rows'] : [];
            foreach ($rows as $r) {
                $out[] = $r;
            }
            $pages = isset($res['pages']) ? (int) $res['pages'] : 1;
            $page++;
        } while ($page <= $pages && $page <= self::MAX_PAGES);

        return $out;
    }

    /**
     * Sucursal que paga el envío de un traspaso: el ORIGEN. Devuelve el nombre
     * legible en MAYÚSCULAS ('COCHABAMBA'), o el bucket "sin sucursal" si el
     * slug no se puede resolver — así el total del mes sigue cuadrando.
     */
    private static function sucursal_of(array $row)
    {
        $slug = trim((string) ($row['origen'] ?? ''));
        if ($slug === '') {
            return FIN_Orders::IBEX_SUC_NONE;
        }
        // La fila ya viene con el nombre resuelto por WC_TP_API::_normalize_row();
        // si el slug es desconocido, esa función devuelve el slug tal cual.
        $name = strtoupper(trim((string) ($row['origen_label'] ?? '')));
        if ($name === '' || $name === strtoupper($slug)) {
            return FIN_Orders::IBEX_SUC_NONE;
        }
        return $name;
    }

    // ── Agregación por mes·sucursal ──────────────────────────────────────────

    /**
     * Traspasos IBEX del rango agrupados por MES (Y-m) y SUCURSAL (origen).
     *
     * Aplica el mismo corte de visualización que el panel de pedidos
     * (`FIN_Orders::ibex_hide_before()`): los meses cerrados fuera del sistema
     * no reaparecen por la puerta de los traspasos.
     *
     * @return array ['Y-m' => ['SUC' => ['total'=>float,'count'=>int,'pending'=>int]]]
     */
    public static function month_sucursales($from, $to)
    {
        $months = [];
        foreach (self::rows($from, $to) as $row) {
            $day = substr((string) ($row['date_created'] ?? ''), 0, 10);
            if ($day === '') {
                continue;
            }
            $m   = substr($day, 0, 7);
            $suc = self::sucursal_of($row);

            if (!isset($months[$m][$suc])) {
                $months[$m][$suc] = ['total' => 0.0, 'count' => 0, 'pending' => 0];
            }

            $costo = isset($row['costo_envio']) && $row['costo_envio'] !== null
                ? (float) $row['costo_envio']
                : 0.0;

            $months[$m][$suc]['count']++;
            $months[$m][$suc]['total'] += $costo;
            if ($costo <= 0) {
                // Sin costo cargado: es la señal de que falta ponerlo en la caja
                // de Traspasos. Bloquea el registro del mes·sucursal.
                $months[$m][$suc]['pending']++;
            }
        }

        foreach ($months as $m => &$sucs) {
            foreach ($sucs as $suc => &$s) {
                $s['total'] = round($s['total'], 2);
            }
            unset($s);
            ksort($sucs);
        }
        unset($sucs);

        $cutoff = FIN_Orders::ibex_hide_before();
        if ($cutoff !== '') {
            $months = array_filter($months, static function ($m) use ($cutoff) {
                return strcmp($m, $cutoff) >= 0;
            }, ARRAY_FILTER_USE_KEY);
        }

        krsort($months);
        return $months;
    }

    /**
     * Total, cantidad y pendientes de UN mes·sucursal. Es la fuente de verdad
     * del monto que se propone en el formulario de Movimientos: se recalcula en
     * el servidor, no se confía en lo que venga por la URL.
     *
     * @return array{total:float,count:int,pending:int}
     */
    public static function summary($month, $sucursal)
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', (string) $month, $mm)) {
            return ['total' => 0.0, 'count' => 0, 'pending' => 0];
        }
        $from = sprintf('%04d-%02d-01', (int) $mm[1], (int) $mm[2]);
        $to   = date('Y-m-t', strtotime($from));

        $suc  = strtoupper(trim((string) $sucursal));
        $data = self::month_sucursales($from, $to);

        // month_sucursales() aplica el corte de visualización; si el mes quedó
        // oculto, no hay nada que registrar (es un mes cerrado fuera del sistema).
        return $data[$month][$suc] ?? ['total' => 0.0, 'count' => 0, 'pending' => 0];
    }

    // ── Egresos separados de la 2.20 (compatibilidad) ────────────────────────

    /**
     * En 2.20 los traspasos se registraban en un egreso APARTE (ref_table
     * `traspaso_shipping_ibex`). En 2.21 el pago es UNO SOLO, así que ese egreso
     * ya no se genera — pero puede existir en la base de quien usó la 2.20, y si
     * se ignorara, el pago unificado volvería a asentar los traspasos y estarían
     * contados dos veces. Por eso se sigue buscando: si aparece, el panel bloquea
     * el mes·sucursal y pide anularlo antes de registrar el pago unificado.
     *
     * @return array|null fila del movimiento, o null si no hay.
     */
    public static function legacy_movement($month, $sucursal)
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', (string) $month, $mm)) {
            return null;
        }
        $key    = (int) $mm[1] * 100 + (int) $mm[2];
        $ref_id = $key * 100 + FIN_Orders::ibex_sucursal_index($sucursal);
        return FIN_Movements::get_by_ref(FIN_Movements::REF_TRASPASO_IBEX, $ref_id);
    }
}
