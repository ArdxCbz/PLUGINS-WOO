<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gastos de importación de una compra (integración con Finanzas) — 3.36+ (DB 2.2).
 *
 * Una compra de importación NO es un gasto: capitaliza al inventario (activo) y
 * golpea el Estado de Resultados como CMV al vender. Pero sus COMPONENTES de
 * costo (mercadería por proveedor, flete, aduana, seguro…) son salidas reales de
 * efectivo. Aquí se adjuntan a la compra en borrador y cada uno dispara un
 * **egreso en una caja de Finanzas que se elige POR GASTO** (puede ser una caja
 * en otra moneda, p.ej. USD; en ese caso el gasto pide su tipo de cambio). Ya no
 * hay una caja única en Configuración. La suma de gastos se muestra de forma
 * informativa, agrupada por moneda. Al recibir la compra los gastos quedan
 * inmutables.
 *
 * Depende de Finanzas (`FIN_Inventory_Costs`). Si no está activo, la sección se
 * deshabilita en la UI (guard `finance_available()`).
 */
class IEM_Import_Costs
{
    // ── Disponibilidad ────────────────────────────────────────────────────

    public static function finance_available()
    {
        return class_exists('FIN_Inventory_Costs') && FIN_Inventory_Costs::is_available();
    }

    /**
     * Cajas activas de Finanzas (con moneda) para el selector POR GASTO.
     *
     * @return array Lista de ['id','name','currency','symbol','is_base','default_rate'].
     */
    public static function accounts()
    {
        if (!self::finance_available()) {
            return [];
        }
        return FIN_Inventory_Costs::accounts();
    }

    // ── Catálogo de motivos de gasto ───────────────────────────────────────

    public static function types($only_active = false)
    {
        global $wpdb;
        $t = IEM_Schema::table('import_expense_types');
        $where = $only_active ? 'WHERE active = 1' : '';
        return $wpdb->get_results("SELECT * FROM $t $where ORDER BY name ASC", ARRAY_A) ?: [];
    }

    public static function get_type($id)
    {
        global $wpdb;
        $t = IEM_Schema::table('import_expense_types');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $id), ARRAY_A) ?: null;
    }

    public static function save_type(array $args)
    {
        $id   = (int) ($args['id'] ?? 0);
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return new WP_Error('iem_ic_type_name', 'El nombre del motivo es obligatorio.');
        }
        if (mb_strlen($name) > 150) {
            return new WP_Error('iem_ic_type_long', 'El nombre supera 150 caracteres.');
        }
        $active = !empty($args['active']) ? 1 : 0;

        global $wpdb;
        $t = IEM_Schema::table('import_expense_types');
        if ($id > 0) {
            $ok = $wpdb->update($t,
                ['name' => $name, 'active' => $active, 'updated_at' => current_time('mysql')],
                ['id' => $id], ['%s', '%d', '%s'], ['%d']);
            return $ok === false ? new WP_Error('iem_ic_db', 'No se pudo actualizar el motivo.') : $id;
        }
        $ok = $wpdb->insert($t, [
            'name' => $name, 'active' => $active,
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
        ], ['%s', '%d', '%s', '%s']);
        return $ok ? (int) $wpdb->insert_id : new WP_Error('iem_ic_db', 'No se pudo crear el motivo.');
    }

    public static function toggle_type($id)
    {
        $row = self::get_type($id);
        if (!$row) {
            return new WP_Error('iem_ic_type_404', 'Motivo no encontrado.');
        }
        global $wpdb;
        $t   = IEM_Schema::table('import_expense_types');
        $new = ((int) $row['active'] === 1) ? 0 : 1;
        $wpdb->update($t, ['active' => $new, 'updated_at' => current_time('mysql')], ['id' => (int) $id], ['%d', '%s'], ['%d']);
        return $new;
    }

    // ── Gastos adjuntados a una compra ─────────────────────────────────────

    /** Gastos de una compra. Por defecto los vigentes (validados + pendientes); excluye reversados. */
    public static function get_for_purchase($purchase_id, $include_reversed = false)
    {
        global $wpdb;
        $t = IEM_Schema::table('purchase_expenses');
        $cond = $include_reversed ? '' : "AND status IN ('active','pending')";
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE purchase_id = %d $cond ORDER BY id ASC",
            (int) $purchase_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Totales de TODOS los gastos de la compra —facturados o no (status active +
     * pending)— en ambas monedas, con el TC promedio ponderado. Suma los
     * equivalentes `amount_bob` y `amount_usd` de cada registro (no el monto nativo
     * por moneda) para dar el gasto total de la importación en Bs y en $, y su TC
     * efectivo: como tc_i = bob_i / usd_i, el promedio ponderado por $ es
     * Σ(bob_i) / Σ(usd_i) = total en Bs ÷ total en $.
     *
     * A diferencia de la vieja Σ por moneda del monto nativo, aquí SÍ entran los
     * gastos pendientes (aún no posteados a Finanzas): es la vista de cuánto se
     * lleva gastado/comprometido en la importación, no solo lo ya facturado.
     *
     * @return array ['bob'=>float, 'usd'=>float, 'tc'=>float, 'count'=>int]
     */
    public static function expense_grand_totals($purchase_id)
    {
        global $wpdb;
        $t = IEM_Schema::table('purchase_expenses');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_bob),0) AS bob, COALESCE(SUM(amount_usd),0) AS usd, COUNT(*) AS n
             FROM $t WHERE purchase_id = %d AND status IN ('active','pending')",
            (int) $purchase_id
        ), ARRAY_A) ?: [];

        $bob = round((float) ($row['bob'] ?? 0), 2);
        $usd = round((float) ($row['usd'] ?? 0), 6);
        return [
            'bob'   => $bob,
            'usd'   => $usd,
            'tc'    => $usd > 0 ? round($bob / $usd, 6) : 0.0,
            'count' => (int) ($row['n'] ?? 0),
        ];
    }

    // Motivos especiales que el listado de compras muestra en columnas propias.
    // El match es por NOMBRE del gasto (snapshot en la fila), sin distinguir
    // mayúsculas/acentos de espacios sobrantes.
    const MOTIVO_PROVEEDORES = 'Importación Pago a Proveedores';
    const MOTIVO_FLETE       = 'Flete Internacional';

    /**
     * Resumen de gastos por compra para el LISTADO (consulta por lote), enfocado
     * en dos motivos: el PAGO A PROVEEDORES (fecha del último gasto con ese
     * motivo) y el PAGO DE FLETE (monto total por moneda con ese motivo).
     *
     * @param int[] $purchase_ids
     * @return array [purchase_id => [
     *   'prov_last' => string|null,                       // fecha del último pago a proveedores
     *   'flete'     => [['currency','symbol','total'], …], // total de flete por moneda
     * ]]
     */
    public static function summary_by_purchase(array $purchase_ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $purchase_ids))));
        if (empty($ids)) {
            return [];
        }
        global $wpdb;
        $t   = IEM_Schema::table('purchase_expenses');
        $in  = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT purchase_id, name, fin_currency AS currency, amount, created_at
             FROM $t WHERE purchase_id IN ($in) AND status = 'active'",
            ...$ids
        ), ARRAY_A) ?: [];

        $norm = function ($s) {
            return trim(function_exists('mb_strtolower') ? mb_strtolower((string) $s) : strtolower((string) $s));
        };
        $key_prov  = $norm(self::MOTIVO_PROVEEDORES);
        $key_flete = $norm(self::MOTIVO_FLETE);

        $out = [];
        foreach ($rows as $r) {
            $pid  = (int) $r['purchase_id'];
            $name = $norm($r['name']);
            if (!isset($out[$pid])) {
                $out[$pid] = ['prov_last' => null, 'flete' => []];
            }
            if ($name === $key_prov) {
                if ($r['created_at'] && (!$out[$pid]['prov_last'] || $r['created_at'] > $out[$pid]['prov_last'])) {
                    $out[$pid]['prov_last'] = (string) $r['created_at'];
                }
            } elseif ($name === $key_flete) {
                $cur = (string) ($r['currency'] ?: 'BOB');
                if (!isset($out[$pid]['flete'][$cur])) {
                    $out[$pid]['flete'][$cur] = 0.0;
                }
                $out[$pid]['flete'][$cur] += (float) $r['amount'];
            }
        }

        // Normaliza el mapa de flete por moneda a lista con símbolo.
        foreach ($out as $pid => &$row) {
            $list = [];
            foreach ($row['flete'] as $cur => $sum) {
                $list[] = ['currency' => $cur, 'symbol' => self::currency_symbol($cur), 'total' => round($sum, 2)];
            }
            $row['flete'] = $list;
        }
        unset($row);

        return $out;
    }

    /** Símbolo de una moneda vía Finanzas (o el código si no está disponible). */
    private static function currency_symbol($code)
    {
        if (class_exists('FIN_Currencies')) {
            return FIN_Currencies::symbol($code);
        }
        return (string) $code;
    }

    /**
     * Valida una caja elegida y resuelve su moneda. Devuelve
     * ['id','currency','symbol','is_base','default_rate'] o WP_Error.
     */
    private static function resolve_account($account_id)
    {
        $account_id = (int) $account_id;
        if ($account_id <= 0) {
            return new WP_Error('iem_ic_account', 'Elige la caja de donde sale el gasto.');
        }
        $meta = FIN_Inventory_Costs::account_currency($account_id);
        if (!$meta) {
            return new WP_Error('iem_ic_account', 'La caja elegida no es válida o está inactiva.');
        }
        $meta['id'] = $account_id;
        return $meta;
    }

    /**
     * Resuelve la moneda dual de un gasto (Bs, USD, TC) y los valores que van a
     * Finanzas (monto NATIVO en la moneda de la caja + rate de conversión a base)
     * a partir de lo que ingresó el operador. Los USDT tienen muchos decimales, así
     * que en vez de teclear el TC a ciegas se ingresan los dos montos y el TC se
     * calcula (caja en $), o se ingresa el TC y se deriva el equivalente (caja en Bs).
     *
     *  - Caja en Bs (base): nativo = Bs, rate = 1. El TC y el equivalente en USD
     *    son informativos: si se da TC se deriva el USD; si se da USD se deriva el
     *    TC; si no se da ninguno, ambos quedan en 0 (gasto en Bs sin referencia $).
     *  - Caja en $ (no base): nativo = USD, rate = TC. Se ingresan USD y Bs y el TC
     *    se deriva (Bs/USD); si falta el Bs pero hay TC, se deriva el Bs.
     *
     * @param array $acc Caja resuelta (resolve_account): usa ['is_base','symbol'].
     * @return array|WP_Error ['native','rate','amount_bob','amount_usd','tc'].
     */
    private static function resolve_amounts(array $acc, $amount_bob, $amount_usd, $tc)
    {
        $bob = round((float) $amount_bob, 2);
        $usd = round((float) $amount_usd, 6);
        $tc  = round((float) $tc, 6);

        if ($acc['is_base']) {
            if ($bob <= 0) {
                return new WP_Error('iem_ic_amount', 'Indica el monto en Bs (mayor a 0).');
            }
            if ($tc > 0) {
                $usd = round($bob / $tc, 6);
            } elseif ($usd > 0) {
                $tc = round($bob / $usd, 6);
            } else {
                $usd = 0.0;
                $tc  = 0.0;
            }
            return ['native' => $bob, 'rate' => 1.0, 'amount_bob' => $bob, 'amount_usd' => $usd, 'tc' => $tc];
        }

        if ($usd <= 0) {
            return new WP_Error('iem_ic_amount', sprintf('Indica el monto en %s (mayor a 0).', $acc['symbol']));
        }
        if ($bob <= 0) {
            if ($tc > 0) {
                $bob = round($usd * $tc, 2);
            } else {
                return new WP_Error('iem_ic_amount', 'Indica el monto en Bs para calcular el tipo de cambio.');
            }
        }
        $tc = round($bob / $usd, 6);
        if ($tc <= 0) {
            return new WP_Error('iem_ic_rate', sprintf('No se pudo calcular el tipo de cambio (Bs por %s).', $acc['symbol']));
        }
        return ['native' => $usd, 'rate' => $tc, 'amount_bob' => $bob, 'amount_usd' => $usd, 'tc' => $tc];
    }

    /**
     * ¿La compra admite operaciones de gasto? Desde 2.6 se permite tanto en
     * borrador como ya recibida (los pagos a veces se hacen DESPUÉS de recibir,
     * para no frenar la venta). Solo se bloquea si la compra no existe o está en
     * otro estado. Devuelve la fila de la compra o WP_Error.
     */
    private static function purchase_accepts_expenses($purchase_id)
    {
        $purchase = IEM_Purchases::get((int) $purchase_id);
        if (!$purchase) {
            return new WP_Error('iem_ic_404', 'Compra no encontrada.');
        }
        if (!in_array($purchase['status'], ['draft', 'received'], true)) {
            return new WP_Error('iem_ic_locked', 'La compra no admite gastos en su estado actual.');
        }
        return $purchase;
    }

    /**
     * Adjunta un gasto a una compra (borrador o recibida) en estado **pendiente**:
     * persiste la fila SIN postear el egreso en Finanzas. El egreso se crea recién
     * al **validar** (`validate()`), un paso manual por registro. Devuelve la fila.
     * contra la caja ELEGIDA. Inserta primero la fila (para tener id como ref) y
     * luego registra; si el registro falla, revierte la inserción.
     *
     * @param int   $account_id Caja de Finanzas elegida para este gasto.
     * @param float $amount_bob Monto del gasto en Bs.
     * @param float $amount_usd Monto del gasto en USD.
     * @param float $tc         Tipo de cambio Bs/$ (para cajas en Bs; en cajas en $
     *                          se recalcula a partir de los dos montos).
     * @return array|WP_Error fila del gasto insertado.
     */
    public static function attach($purchase_id, $type_id, $account_id, $amount_bob, $amount_usd, $tc = 0)
    {
        $purchase = self::purchase_accepts_expenses($purchase_id);
        if (is_wp_error($purchase)) {
            return $purchase;
        }
        if (!self::finance_available()) {
            return new WP_Error('iem_ic_unconfigured',
                'El plugin Finanzas no está activo; no se pueden registrar gastos de importación.');
        }
        $type = self::get_type((int) $type_id);
        if (!$type) {
            return new WP_Error('iem_ic_type_404', 'Motivo de gasto inválido.');
        }
        $acc = self::resolve_account($account_id);
        if (is_wp_error($acc)) {
            return $acc;
        }
        $vals = self::resolve_amounts($acc, $amount_bob, $amount_usd, $tc);
        if (is_wp_error($vals)) {
            return $vals;
        }

        global $wpdb;
        $t = IEM_Schema::table('purchase_expenses');
        $now = current_time('mysql');
        // Se inserta en estado `pending`: NO se postea el egreso en Finanzas aún
        // (fin_movement_id=0). El egreso se crea al validar el registro.
        $ins = $wpdb->insert($t, [
            'purchase_id'     => (int) $purchase_id,
            'type_id'         => (int) $type['id'],
            'name'            => (string) $type['name'],
            'amount'          => $vals['native'],
            'amount_bob'      => $vals['amount_bob'],
            'amount_usd'      => $vals['amount_usd'],
            'tc'              => $vals['tc'],
            'fin_account_id'  => (int) $acc['id'],
            'fin_currency'    => (string) $acc['currency'],
            'fin_rate'        => $vals['rate'],
            'fin_movement_id' => 0,
            'status'          => 'pending',
            'validated_at'    => null,
            'created_by'      => (int) get_current_user_id(),
            'created_at'      => $now,
            'updated_at'      => $now,
        ], ['%d', '%d', '%s', '%f', '%f', '%f', '%f', '%d', '%s', '%f', '%d', '%s', '%s', '%d', '%s', '%s']);
        if (!$ins) {
            return new WP_Error('iem_ic_db', 'No se pudo guardar el gasto.');
        }
        $exp_id = (int) $wpdb->insert_id;

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $exp_id), ARRAY_A) ?: [];
    }

    /**
     * Valida un gasto pendiente: postea el egreso en Finanzas contra su caja y lo
     * pasa a `active` (guardando `fin_movement_id` y `validated_at`). Paso manual
     * por registro (botón "Validar"). Idempotente-seguro: si ya está validado,
     * devuelve la fila tal cual.
     *
     * @return array|WP_Error fila del gasto.
     */
    public static function validate($expense_id)
    {
        global $wpdb;
        $t   = IEM_Schema::table('purchase_expenses');
        $exp = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $expense_id), ARRAY_A);
        if (!$exp) {
            return new WP_Error('iem_ic_404', 'Gasto no encontrado.');
        }
        if ($exp['status'] === 'active' && (int) $exp['fin_movement_id'] > 0) {
            return $exp; // ya validado
        }
        if ($exp['status'] !== 'pending') {
            return new WP_Error('iem_ic_state', 'El gasto no está pendiente de validación.');
        }
        $purchase = self::purchase_accepts_expenses((int) $exp['purchase_id']);
        if (is_wp_error($purchase)) {
            return $purchase;
        }
        if (!self::finance_available()) {
            return new WP_Error('iem_ic_unconfigured', 'Finanzas no está disponible para validar el gasto.');
        }
        $acc = self::resolve_account((int) $exp['fin_account_id']);
        if (is_wp_error($acc)) {
            return $acc;
        }
        $native = round((float) $exp['amount'], 2);
        $rate   = $acc['is_base'] ? 1.0 : round((float) $exp['fin_rate'], 6);

        $desc = sprintf('Gasto importación: %s — compra %s', $exp['name'], $purchase['code']);
        $mv = FIN_Inventory_Costs::register((int) $acc['id'], $native, $desc, (int) $exp['id'], (string) $purchase['code'], '', $rate);
        if (is_wp_error($mv)) {
            return $mv;
        }
        $wpdb->update($t, [
            'fin_movement_id' => (int) $mv,
            'status'          => 'active',
            'validated_at'    => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ], ['id' => (int) $exp['id']], ['%d', '%s', '%s', '%s'], ['%d']);

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $exp['id']), ARRAY_A) ?: [];
    }

    /**
     * Edita un gasto (montos y/o caja y/o TC): reversa el egreso anterior
     * (contrasiento) y registra uno nuevo. Solo en borrador. Si no se pasa
     * $account_id se mantiene la caja actual del gasto.
     *
     * @param int   $account_id Caja (0 = mantener la actual del gasto).
     * @param float $amount_bob Monto en Bs.
     * @param float $amount_usd Monto en USD.
     * @param float $tc         Tipo de cambio Bs/$.
     */
    public static function update_expense($expense_id, $account_id, $amount_bob, $amount_usd, $tc = 0)
    {
        global $wpdb;
        $t   = IEM_Schema::table('purchase_expenses');
        $exp = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $expense_id), ARRAY_A);
        if (!$exp) {
            return new WP_Error('iem_ic_404', 'Gasto no encontrado.');
        }
        $purchase = self::purchase_accepts_expenses((int) $exp['purchase_id']);
        if (is_wp_error($purchase)) {
            return $purchase;
        }
        if (!self::finance_available()) {
            return new WP_Error('iem_ic_unconfigured', 'Finanzas no está disponible para editar el gasto.');
        }
        $account_id = (int) $account_id ?: (int) $exp['fin_account_id'];
        $acc = self::resolve_account($account_id);
        if (is_wp_error($acc)) {
            return $acc;
        }
        $vals = self::resolve_amounts($acc, $amount_bob, $amount_usd, $tc);
        if (is_wp_error($vals)) {
            return $vals;
        }

        $is_validated = ($exp['status'] === 'active' && (int) $exp['fin_movement_id'] > 0);

        // Datos base que se actualizan siempre (montos, caja, moneda, TC).
        $data = [
            'amount'         => $vals['native'],
            'amount_bob'     => $vals['amount_bob'],
            'amount_usd'     => $vals['amount_usd'],
            'tc'             => $vals['tc'],
            'fin_account_id' => (int) $acc['id'],
            'fin_currency'   => (string) $acc['currency'],
            'fin_rate'       => $vals['rate'],
            'updated_at'     => current_time('mysql'),
        ];
        $fmt = ['%f', '%f', '%f', '%f', '%d', '%s', '%f', '%s'];

        if ($is_validated) {
            // Gasto ya posteado: reversa el movimiento anterior y registra uno nuevo
            // (re-validación con los nuevos importes). Sigue `active`.
            FIN_Inventory_Costs::reverse((int) $exp['fin_movement_id']);
            $desc = sprintf('Gasto importación: %s — compra %s', $exp['name'], $purchase['code']);
            $mv = FIN_Inventory_Costs::register((int) $acc['id'], $vals['native'], $desc, (int) $exp['id'], (string) $purchase['code'], '', $vals['rate']);
            if (is_wp_error($mv)) {
                return $mv;
            }
            $data['fin_movement_id'] = (int) $mv;
            $fmt[] = '%d';
            $data['validated_at'] = current_time('mysql');
            $fmt[] = '%s';
        }
        // Gasto pendiente: solo se actualizan los datos (no toca Finanzas; sigue pending).

        $wpdb->update($t, $data, ['id' => (int) $exp['id']], $fmt, ['%d']);

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $exp['id']), ARRAY_A) ?: [];
    }

    /**
     * Elimina un gasto (compra en borrador o recibida): si estaba validado reversa
     * su egreso; si era pendiente solo borra la fila.
     */
    public static function delete_expense($expense_id)
    {
        global $wpdb;
        $t   = IEM_Schema::table('purchase_expenses');
        $exp = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $expense_id), ARRAY_A);
        if (!$exp) {
            return new WP_Error('iem_ic_404', 'Gasto no encontrado.');
        }
        $purchase = self::purchase_accepts_expenses((int) $exp['purchase_id']);
        if (is_wp_error($purchase)) {
            return $purchase;
        }
        if ((int) $exp['fin_movement_id'] > 0 && self::finance_available()) {
            FIN_Inventory_Costs::reverse((int) $exp['fin_movement_id']);
        }
        $wpdb->delete($t, ['id' => (int) $exp['id']], ['%d']);
        return true;
    }

    /** Reversa y borra todos los gastos de una compra (al borrar la compra draft). */
    public static function reverse_all_for_purchase($purchase_id)
    {
        foreach (self::get_for_purchase($purchase_id) as $exp) {
            if ((int) $exp['fin_movement_id'] > 0 && self::finance_available()) {
                FIN_Inventory_Costs::reverse((int) $exp['fin_movement_id']);
            }
        }
        global $wpdb;
        $wpdb->delete(IEM_Schema::table('purchase_expenses'), ['purchase_id' => (int) $purchase_id], ['%d']);
    }
}
