<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reportes financieros (v1.0). Todas las consultas operan sobre fin_movements.
 *
 * Convención de exclusiones para reportes de resultado/categoría:
 *  - Solo cuentan movimientos de tipo `ingreso` y `egreso`.
 *  - Se EXCLUYEN `opening` (saldo inicial, no es resultado), `transfer_in` y
 *    `transfer_out` (mueven dinero entre cuentas, no generan resultado).
 *  - Los contrasientos (reverses_id IS NOT NULL) SÍ cuentan: un ingreso anulado
 *    genera un egreso de anulación que neutraliza el original en el período,
 *    de modo que el neto refleja la corrección.
 *
 * El flujo de caja sí considera todas las entradas/salidas de efectivo
 * (incluye transferencias y aperturas como movimientos de caja).
 */
class FIN_Reports
{
    // Estado de Resultados — Ventas desde pedidos (devengado). Estados (slugs SIN
    // el prefijo 'wc-') que se EXCLUYEN de las ventas (cancelado/retorno) y los
    // que cuentan como COBRADO/completado. Configurables; si la opción nunca se
    // guardó (null) se autodetectan por el nombre del estado.
    const OPT_SALES_EXCLUDED  = 'fin_is_excluded_statuses';
    const OPT_SALES_COLLECTED = 'fin_is_collected_statuses';

    /** Estados (slugs sin 'wc-') excluidos de Ventas. */
    public static function excluded_statuses()
    {
        $v = get_option(self::OPT_SALES_EXCLUDED, null);
        if (!is_array($v)) {
            return self::default_excluded_statuses();
        }
        return self::normalize_status_slugs($v);
    }

    /** Estados (slugs sin 'wc-') que cuentan como cobrado/completado. */
    public static function collected_statuses()
    {
        $v = get_option(self::OPT_SALES_COLLECTED, null);
        if (!is_array($v)) {
            return self::default_collected_statuses();
        }
        return self::normalize_status_slugs($v);
    }

    /** Autodetección: estados cuyo nombre sugiere cancelación/retorno. */
    public static function default_excluded_statuses()
    {
        $out = [];
        foreach (self::all_order_statuses() as $slug => $label) {
            if (preg_match('/cancel|anul|retorno|reembols|refund|devol/i', $label)) {
                $out[] = self::strip_wc($slug);
            }
        }
        return $out;
    }

    /** Autodetección: estados cuyo nombre sugiere cobrado/completado/entregado. */
    public static function default_collected_statuses()
    {
        $out = [];
        foreach (self::all_order_statuses() as $slug => $label) {
            if (preg_match('/complet|entreg|pagad|cobrad/i', $label)) {
                $out[] = self::strip_wc($slug);
            }
        }
        return $out;
    }

    /** Todos los estados de pedido registrados: [wc-slug => etiqueta]. */
    public static function all_order_statuses()
    {
        return function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
    }

    private static function strip_wc($slug)
    {
        return preg_replace('/^wc-/', '', (string) $slug);
    }

    private static function normalize_status_slugs(array $slugs)
    {
        $out = [];
        foreach ($slugs as $s) {
            $s = self::strip_wc(sanitize_key((string) $s));
            if ($s !== '') {
                $out[$s] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Ventas del período desde los pedidos de WooCommerce (base devengada),
     * descompuestas comercialmente. Considera los pedidos cuya FECHA DE CREACIÓN
     * cae en el rango, EXCEPTO los estados excluidos (cancelado/retorno).
     *
     *   - bruta      = Σ subtotal de productos a precio de lista (sin descuento,
     *                  sin envío) = get_subtotal().
     *   - descuentos = Σ descuento del pedido = get_total_discount().
     *   - neta       = bruta − descuentos (ingreso por productos).
     *   - shipping   = Σ envío cobrado al cliente = get_shipping_total() (línea
     *                  aparte; no entra a "Ventas netas").
     *
     * El desglose Cobrado / Por cobrar se calcula sobre la VENTA NETA (productos),
     * separando por estado del pedido (cobrado/completado vs resto).
     *
     * @return array ['bruta','descuentos','neta','shipping','collected','pending',
     *                'count','collected_count','pending_count','configured']
     */
    public static function sales_from_orders($from, $to)
    {
        $zero = [
            'bruta' => 0.0, 'descuentos' => 0.0, 'neta' => 0.0, 'shipping' => 0.0,
            'collected' => 0.0, 'pending' => 0.0,
            'count' => 0, 'collected_count' => 0, 'pending_count' => 0,
            'configured' => false,
        ];
        if (!function_exists('wc_get_orders')) {
            return $zero;
        }

        $excluded   = self::excluded_statuses();
        $collected  = array_flip(self::collected_statuses());
        $configured = get_option(self::OPT_SALES_EXCLUDED, null) !== null;

        // Estados incluidos = todos los registrados menos los excluidos.
        $all      = array_map([__CLASS__, 'strip_wc'], array_keys(self::all_order_statuses()));
        $included = array_values(array_diff($all, $excluded));
        if (empty($included)) {
            return array_merge($zero, ['configured' => $configured]);
        }

        list($from_ts, $to_ts) = fin_order_range_ts($from, $to);
        if (!$from_ts || !$to_ts || $from_ts > $to_ts) {
            return array_merge($zero, ['configured' => $configured]);
        }

        $orders = wc_get_orders([
            'limit'        => -1,
            'type'         => 'shop_order',
            'status'       => $included,
            'date_created' => $from_ts . '...' . $to_ts,
            'return'       => 'objects',
        ]);

        $bruta = 0.0; $desc = 0.0; $ship = 0.0;
        $neta_coll = 0.0; $count = 0; $coll_count = 0;
        foreach ($orders as $o) {
            if (!($o instanceof WC_Order)) {
                continue;
            }
            $sub  = (float) $o->get_subtotal();        // productos a precio lista (sin desc, sin envío)
            $d    = (float) $o->get_total_discount();  // descuento (positivo)
            $sh   = (float) $o->get_shipping_total();  // envío cobrado
            $net  = $sub - $d;

            $bruta += $sub;
            $desc  += $d;
            $ship  += $sh;
            $count++;
            if (isset($collected[$o->get_status()])) {
                $neta_coll += $net;
                $coll_count++;
            }
        }

        $neta = $bruta - $desc;
        return [
            'bruta'           => round($bruta, 2),
            'descuentos'      => round($desc, 2),
            'neta'            => round($neta, 2),
            'shipping'        => round($ship, 2),
            'collected'       => round($neta_coll, 2),
            'pending'         => round($neta - $neta_coll, 2),
            'count'           => $count,
            'collected_count' => $coll_count,
            'pending_count'   => $count - $coll_count,
            'configured'      => $configured,
        ];
    }

    /**
     * Retención de IBEX (7% no depositado) del período — gasto operativo que NO
     * pasa por las cajas (IBEX descuenta el 7% del total en pedidos con envío
     * IBEX + pago Contra Entrega y deposita el resto). Se calcula sobre TODOS los
     * pedidos del rango (misma base devengada que las Ventas: excl. cancelado/
     * retorno) reutilizando la regla del plugin DEMV (fuente única de verdad).
     *
     * @return float monto retenido (>= 0); 0 si DEMV no está disponible.
     */
    public static function ibex_retention($from, $to)
    {
        if (!class_exists('HPOS_Ardxoz_Woo_DEMV_Calculator') || !function_exists('wc_get_orders')) {
            return 0.0;
        }

        $excluded = self::excluded_statuses();
        $all      = array_map([__CLASS__, 'strip_wc'], array_keys(self::all_order_statuses()));
        $included = array_values(array_diff($all, $excluded));
        if (empty($included)) {
            return 0.0;
        }

        list($from_ts, $to_ts) = fin_order_range_ts($from, $to);
        if (!$from_ts || !$to_ts || $from_ts > $to_ts) {
            return 0.0;
        }

        $ids = wc_get_orders([
            'limit'        => -1,
            'type'         => 'shop_order',
            'status'       => $included,
            'date_created' => $from_ts . '...' . $to_ts,
            'return'       => 'ids',
        ]);
        if (empty($ids)) {
            return 0.0;
        }

        $r = HPOS_Ardxoz_Woo_DEMV_Calculator::sum_ibex_cod_retention($ids);
        return round((float) ($r['retained'] ?? 0), 2);
    }

    /**
     * Flujo de caja por período. Agrupa ingresos/egresos de caja por mes o día.
     *
     * @param string $from         'Y-m-d'
     * @param string $to           'Y-m-d'
     * @param string $granularity  'month' | 'day'
     * @return array [currency_code => lista de ['period','ingresos','egresos','neto']]
     *               (separado por moneda; no se consolida).
     */
    public static function cash_flow($from, $to, $granularity = 'month')
    {
        global $wpdb;
        $t = FIN_Schema::table('movements');

        $fmt = ($granularity === 'day') ? '%%Y-%%m-%%d' : '%%Y-%%m';
        $period_expr = "DATE_FORMAT(movement_date, '$fmt')";

        list($where, $params) = self::date_where($from, $to);
        // Para flujo de caja consideramos toda entrada/salida real de efectivo:
        // ingreso, egreso y las dos patas de transferencia (opening lo dejamos
        // fuera por no ser flujo del período operativo).
        $where[]  = "type IN ('ingreso','egreso','transfer_in','transfer_out')";

        $sql = "SELECT currency, $period_expr AS period,
                       SUM(CASE WHEN direction = 'I' THEN amount ELSE 0 END) AS ingresos,
                       SUM(CASE WHEN direction = 'O' THEN amount ELSE 0 END) AS egresos
                FROM $t
                WHERE " . implode(' AND ', $where) . "
                GROUP BY currency, period
                ORDER BY currency ASC, period ASC";
        $sql = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            $ing = (float) $r['ingresos'];
            $egr = (float) $r['egresos'];
            $out[$cur][] = [
                'period'   => (string) $r['period'],
                'ingresos' => $ing,
                'egresos'  => $egr,
                'neto'     => $ing - $egr,
            ];
        }
        return $out;
    }

    /**
     * Gastos por categoría (solo egresos) en el rango, SEPARADO por moneda.
     * Ordenado de mayor a menor dentro de cada moneda.
     *
     * @return array [currency_code => lista de ['category_id','name','total']]
     */
    public static function expenses_by_category($from, $to)
    {
        global $wpdb;
        $m = FIN_Schema::table('movements');
        $c = FIN_Schema::table('categories');

        list($where, $params) = self::date_where($from, $to, 'm');
        $where[] = "m.type = 'egreso'";

        $sql = "SELECT m.currency AS currency,
                       m.category_id AS category_id,
                       COALESCE(c.name, 'Sin clasificar') AS name,
                       SUM(m.amount) AS total
                FROM $m m
                LEFT JOIN $c c ON c.id = m.category_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY m.currency, m.category_id, name
                ORDER BY m.currency ASC, total DESC";
        $sql = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['currency']][] = [
                'category_id' => (int) $r['category_id'],
                'name'        => (string) $r['name'],
                'total'       => (float) $r['total'],
            ];
        }
        return $out;
    }

    /**
     * Estado de Resultados (acumulado) del rango, estructurado por Grupos
     * contables (FIN_Groups):
     *
     *     VENTAS (devengado, desde pedidos) + Otros ingresos
     *   (−) CMV  (Costo de Mercadería Vendida, leído del Kardex)
     *   = UTILIDAD BRUTA
     *   (−) Gastos (Comercialización / Administrativos / Operativos / Financieros)
     *   = UTILIDAD NETA
     *
     * **Ventas (base devengada):** se calculan desde los pedidos de WooCommerce
     * (`sales_from_orders()`): total de pedidos cuya fecha de creación cae en el
     * rango, EXCEPTO estados excluidos (cancelado/retorno). Se reporta además el
     * desglose cobrado (completado) / por cobrar como memorándum (no afecta la
     * utilidad; lo por cobrar es una cuenta por cobrar del balance). El ledger
     * NO alimenta la línea de Ventas (evita duplicar con los depósitos/cobros).
     *
     * "Otros ingresos", CMV y Gastos sí salen del ledger/Kardex. Excluye los
     * grupos que NO afectan resultado (Compra de Inventario = activo; Movimientos
     * Patrimoniales). Los contrasientos netean dentro de su grupo (misma cuenta
     * contable), por eso se suma `direction` firmada en vez de filtrar por tipo.
     *
     * @return array Estructura lista para la plantilla (ver claves abajo).
     */
    public static function income_statement($from, $to)
    {
        $by_group = self::net_by_group_motivo($from, $to);

        $ingresos = [];   // SOLO 'otros_ingresos' (Ventas viene de los pedidos)
        $gastos   = [];   // grupos de gasto
        $total_otros = 0.0;
        $total_gas   = 0.0;

        foreach ($by_group as $group_key => $info) {
            $group = FIN_Groups::get($group_key);
            if (!$group || !$group['affects_result']) {
                continue; // excluidos: sin grupo, compra inventario, patrimonial
            }
            $section = $group['pl_section'];

            if ($section === 'ventas') {
                // Las Ventas se calculan desde los pedidos (devengado). Los ingresos
                // del ledger en el grupo Ventas (depósitos/cobros) NO se cuentan aquí
                // para no duplicar: representan cobro, no la venta devengada.
                continue;
            } elseif ($section === 'otros_ingresos') {
                // En ingresos el neto firmado ya es positivo (I suma, O resta).
                $total = $info['net'];
                $motivos = [];
                foreach ($info['motivos'] as $name => $net) {
                    $motivos[] = ['name' => $name, 'total' => $net];
                }
                $ingresos[] = [
                    'section' => $section,
                    'label'   => $group['label'],
                    'total'   => $total,
                    'motivos' => $motivos,
                    'sort'    => $group['sort_order'],
                ];
                $total_otros += $total;
            } elseif ($section === 'gastos') {
                // En gastos el neto firmado es negativo (O resta); el monto del
                // gasto es su valor absoluto.
                $total = -$info['net'];
                $motivos = [];
                foreach ($info['motivos'] as $name => $net) {
                    $motivos[] = ['name' => $name, 'total' => -$net];
                }
                $gastos[] = [
                    'group'   => $group_key,
                    'label'   => $group['label'],
                    'total'   => $total,
                    'motivos' => $motivos,
                    'sort'    => $group['sort_order'],
                ];
                $total_gas += $total;
            }
        }

        usort($ingresos, function ($a, $b) { return $a['sort'] <=> $b['sort']; });
        usort($gastos,   function ($a, $b) { return $a['sort'] <=> $b['sort']; });

        // Ventas devengadas desde los pedidos (excl. cancelado/retorno):
        // Venta neta (productos) + Envío cobrado + Otros ingresos del ledger.
        $sales     = self::sales_from_orders($from, $to);
        $total_ing = $sales['neta'] + $sales['shipping'] + $total_otros;

        $cmv      = self::cmv_from_kardex($from, $to);
        $util_bruta = $total_ing - $cmv['total'];

        // Retención IBEX (7% no depositado): gasto operativo REAL que no pasa por
        // las cajas. Se suma a los gastos y reduce la utilidad neta.
        $ibex_retention = self::ibex_retention($from, $to);
        $total_gas += $ibex_retention;

        $util_neta  = $util_bruta - $total_gas;

        // Informativo (NO afecta la utilidad): compras de inventario del período
        // (costos de importación capitalizados, grupo `compra_inventario`, egresos
        // de la integración con el plugin de Inventario). El monto es el neto firmado
        // en valor absoluto (los egresos restan; los contrasientos netean).
        $inv_net = isset($by_group[FIN_Groups::COMPRA_INVENTARIO])
            ? (float) $by_group[FIN_Groups::COMPRA_INVENTARIO]['net'] : 0.0;
        $inventory_purchases = -$inv_net;

        return [
            'ventas'                  => $sales,        // total/collected/pending/counts
            'ingresos'                => $ingresos,     // solo "otros ingresos" (ledger)
            'total_ingresos'          => $total_ing,
            'cmv'                     => $cmv['total'],
            'cmv_sales_without_cost'  => $cmv['sales_without_cost'],
            'cmv_available'           => $cmv['available'],
            'utilidad_bruta'          => $util_bruta,
            'gastos'                  => $gastos,
            'ibex_retention'          => $ibex_retention, // gasto operativo (no pasa por caja)
            'total_gastos'            => $total_gas,
            'utilidad_neta'           => $util_neta,
            'inventory_purchases'     => $inventory_purchases, // informativo
            'other_currencies'        => self::other_currency_ledger($from, $to), // informativo
        ];
    }

    /**
     * Movimientos del ledger (ingreso/egreso) en monedas DISTINTAS a la base,
     * agregados por moneda. Informativo para el Estado de Resultados (no entran
     * a la utilidad en Bs; se reportan aparte porque no se consolidan).
     *
     * @return array [currency_code => ['ingresos'=>float,'egresos'=>float,'neto'=>float]]
     */
    private static function other_currency_ledger($from, $to)
    {
        global $wpdb;
        $m = FIN_Schema::table('movements');

        // Por DEVENGO: este bloque es parte del Estado de Resultados.
        list($where, $params) = self::date_where($from, $to, 'm', 'accrual_date');
        $where[]  = "m.type IN ('ingreso','egreso')";
        $where[]  = "m.currency <> %s";
        $params[] = FIN_Currencies::BASE_CODE;

        $sql = "SELECT m.currency AS currency,
                       SUM(CASE WHEN m.type = 'ingreso' THEN m.amount ELSE 0 END) AS ingresos,
                       SUM(CASE WHEN m.type = 'egreso'  THEN m.amount ELSE 0 END) AS egresos
                FROM $m m
                WHERE " . implode(' AND ', $where) . "
                GROUP BY m.currency
                ORDER BY m.currency ASC";
        $sql  = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $ing = (float) $r['ingresos'];
            $egr = (float) $r['egresos'];
            $out[(string) $r['currency']] = [
                'ingresos' => round($ing, 2),
                'egresos'  => round($egr, 2),
                'neto'     => round($ing - $egr, 2),
            ];
        }
        return $out;
    }

    /**
     * Neto firmado (I=+, O=−) por grupo contable y, dentro, por motivo, para
     * movimientos ingreso/egreso del rango.
     *
     * @return array group_key => ['net'=>float, 'motivos'=>[name=>net]]
     */
    private static function net_by_group_motivo($from, $to)
    {
        global $wpdb;
        $m = FIN_Schema::table('movements');
        $c = FIN_Schema::table('categories');

        // Por DEVENGO, no por fecha de pago: el Estado de Resultados imputa cada
        // gasto al mes que lo generó (ver date_where).
        list($where, $params) = self::date_where($from, $to, 'm', 'accrual_date');
        $where[]  = "m.type IN ('ingreso','egreso')";
        // El Estado de Resultados es un reporte en moneda BASE (Bs): Ventas vienen
        // de pedidos y CMV del Kardex, ambos en Bs. Los movimientos del ledger en
        // otras monedas NO entran a la utilidad (se muestran como informativo).
        $where[]  = "m.currency = %s";
        $params[] = FIN_Currencies::BASE_CODE;

        $sql = "SELECT COALESCE(c.group_key, '') AS group_key,
                       COALESCE(c.name, 'Sin clasificar') AS name,
                       SUM(CASE WHEN m.direction = 'I' THEN m.amount ELSE -m.amount END) AS net
                FROM $m m
                LEFT JOIN $c c ON c.id = m.category_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY group_key, name";
        $sql  = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $gk  = (string) $r['group_key'];
            $net = (float) $r['net'];
            if (!isset($out[$gk])) {
                $out[$gk] = ['net' => 0.0, 'motivos' => []];
            }
            $out[$gk]['net'] += $net;
            $out[$gk]['motivos'][(string) $r['name']] = $net;
        }
        return $out;
    }

    /**
     * CMV (Costo de Mercadería Vendida) del rango, leído del Kardex del plugin
     * de Inventario: Σ(qty × unit_cost) de ventas − devoluciones.
     *
     * `unit_cost` proviene del meta `_alg_wc_cog_cost`. Las ventas sin costo
     * cargado cuentan como costo 0 y subestiman el CMV; se reporta cuántas son
     * para que el usuario sepa si el dato es confiable.
     *
     * @return array ['total'=>float, 'sales_without_cost'=>int, 'available'=>bool]
     */
    public static function cmv_from_kardex($from, $to)
    {
        global $wpdb;
        $kardex = class_exists('IEM_Schema')
            ? IEM_Schema::table('kardex')
            : $wpdb->prefix . 'iem_kardex';

        if ($wpdb->get_var("SHOW TABLES LIKE '$kardex'") !== $kardex) {
            return ['total' => 0.0, 'sales_without_cost' => 0, 'available' => false];
        }

        $w = ['type IN (%s, %s)'];
        $p = ['sale', 'sale_refund'];
        if (!empty($from)) { $w[] = 'movement_date >= %s'; $p[] = $from . ' 00:00:00'; }
        if (!empty($to))   { $w[] = 'movement_date <= %s'; $p[] = $to   . ' 23:59:59'; }
        $where = implode(' AND ', $w);

        $total = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(CASE WHEN type = 'sale'        THEN qty * COALESCE(unit_cost, 0)
                             WHEN type = 'sale_refund' THEN -qty * COALESCE(unit_cost, 0)
                             ELSE 0 END)
             FROM $kardex WHERE $where",
            ...$p
        ));

        // Ventas (sale) del rango sin costo conocido.
        $w2 = ['type = %s', 'unit_cost IS NULL'];
        $p2 = ['sale'];
        if (!empty($from)) { $w2[] = 'movement_date >= %s'; $p2[] = $from . ' 00:00:00'; }
        if (!empty($to))   { $w2[] = 'movement_date <= %s'; $p2[] = $to   . ' 23:59:59'; }
        $without = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $kardex WHERE " . implode(' AND ', $w2),
            ...$p2
        ));

        return [
            'total'              => round($total, 2),
            'sales_without_cost' => $without,
            'available'          => true,
        ];
    }

    /**
     * Construye el WHERE de rango de fechas. Devuelve [array $where, array $params].
     * $alias permite prefijar la columna (p.ej. 'm' => 'm.movement_date').
     *
     * $field elige QUÉ fecha se filtra, y no es un detalle:
     *  - 'movement_date' (default) = fecha de CAJA. La usan Flujo de caja y Gastos
     *    por categoría: preguntan cuándo SALIÓ la plata.
     *  - 'accrual_date' = fecha de DEVENGO. La usa el Estado de Resultados: pregunta
     *    a qué mes PERTENECE el gasto. El pago mensual de IBEX se hace en julio pero
     *    devenga en junio (son las ventas y traspasos de junio); imputarlo a julio
     *    haría ver a junio más rentable de lo que fue y a julio, peor.
     * Para el resto de movimientos ambas columnas son iguales, así que ningún otro
     * reporte cambia de resultado.
     */
    private static function date_where($from, $to, $alias = '', $field = 'movement_date')
    {
        $field = ($field === 'accrual_date') ? 'accrual_date' : 'movement_date';
        $col   = ($alias !== '') ? "$alias.$field" : $field;
        $where = ['1=1'];
        $params = [];
        if (!empty($from)) {
            $where[]  = "$col >= %s";
            $params[] = $from . ' 00:00:00';
        }
        if (!empty($to)) {
            $where[]  = "$col <= %s";
            $params[] = $to . ' 23:59:59';
        }
        return [$where, $params];
    }
}
