<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * RENDICIÓN DE CAJA CHICA (v2.17).
 *
 * El ciclo clásico de una caja chica: se RINDE cuenta de todo lo gastado hasta
 * una fecha, y recién entonces se REPONE el fondo. Esta clase implementa la
 * rendición; la reposición se registra a mano como cualquier movimiento.
 *
 * No es un arqueo: no hay conteo físico, ni responsable, ni diferencia. Lo que
 * hace es fijar la LÍNEA DE PARTIDA de la caja con un candado por fecha.
 *
 * EL CICLO:
 *   1. Se validan todos los egresos de envío pendientes hasta la fecha de corte.
 *      Recién ahí el saldo de la caja es real: mientras haya pedidos sin validar,
 *      la caja miente (esa plata ya salió, pero no está en el ledger).
 *   2. Se RINDE la caja a esa fecha. El saldo al corte es la DEUDA de la caja:
 *      cuánto hay que reponer.
 *   3. La REPOSICIÓN se registra a mano en Movimientos, con fecha POSTERIOR a la
 *      rendición y por el monto que se decida (a veces hasta dejarla en cero, a
 *      veces con colchón: es variable, por eso la rendición no la calcula ni la
 *      registra — solo muestra el saldo).
 *   4. Como nada puede entrar antes de la rendición, la caja corre limpia desde
 *      ahí.
 *
 * POR QUÉ LOS PENDIENTES BLOQUEAN LA RENDICIÓN: si se rinde con egresos de envío
 * sin validar, el saldo al corte está inflado y se repone la cifra equivocada.
 * Ese es todo el motivo del bloqueo. (Un pedido elegible sin costo cargado
 * también cuenta como pendiente, a propósito: es la señal de que falta poner el
 * monto. Si el pedido de verdad no lleva costo de envío, se le cambia el método
 * de envío en el pedido y deja de ser elegible. Y los pedidos anteriores al
 * ARRANQUE del sistema —saldados fuera de Finanzas— se excluyen con el corte
 * `FIN_Orders::ship_hide_before()`, o bloquearían la rendición para siempre.)
 *
 * POR QUÉ EL CANDADO BLOQUEA TODO Y NO SOLO EGRESOS: un ingreso o una
 * transferencia con fecha anterior a la rendición también mueven el saldo al
 * corte, y por tanto invalidan la reposición ya calculada. Rendido es rendido.
 *
 * RENDIR ES IRREVERSIBLE: no hay reapertura (ni método, ni endpoint admin-post).
 * Por eso `FIN_Admin::handle_close_cash()` exige doble validación —checkbox de
 * reconocimiento comprobado EN EL SERVIDOR, más confirmación en el navegador— y
 * `close()` rechaza cortes futuros o anteriores a la rendición vigente: una vez
 * rendida una fecha, ese período queda firmado para siempre. Un error dentro del
 * período rendido se corrige con un movimiento nuevo, fechado después.
 *
 * SIN TABLA: todo vive en la opción `fin_cash_lock` (la clave conserva el nombre
 * original para no perder el estado ya guardado en instalaciones existentes; el
 * concepto es la rendición). Una rendición es una fecha más su firma; el
 * historial deja registro de las anteriores.
 */
class FIN_Rendicion
{
    const OPTION = 'fin_cash_lock';

    /** Rendiciones anteriores que se conservan como registro. */
    const HISTORY_MAX = 12;

    // ── La caja chica ────────────────────────────────────────────────────────

    /**
     * La caja chica NO se elige: es la cuenta configurada para el egreso de costo
     * de envío courier. El cierre existe justamente para dejar limpia esa caja.
     *
     * @return int 0 si no está configurada.
     */
    public static function account_id()
    {
        return FIN_Orders::shipping_account_id();
    }

    /** Fila de la cuenta de caja chica, o null. */
    public static function account()
    {
        $id = self::account_id();
        return $id > 0 ? FIN_Accounts::get($id) : null;
    }

    // ── Estado del cierre ────────────────────────────────────────────────────

    /**
     * @return array ['date'=>'Y-m-d'|'', 'by'=>int, 'at'=>string, 'balance'=>float,
     *                'history'=>array]
     */
    public static function state()
    {
        $v = get_option(self::OPTION, []);
        if (!is_array($v)) {
            $v = [];
        }
        return array_merge([
            'date'    => '',
            'by'      => 0,
            'at'      => '',
            'balance' => 0.0,
            'history' => [],
        ], $v);
    }

    /**
     * Fecha hasta la que la caja está CERRADA (inclusive), o '' si está abierta.
     *
     * @return string 'Y-m-d' | ''
     */
    public static function locked_until()
    {
        $s = self::state();
        return (string) $s['date'];
    }

    /**
     * ¿Un movimiento en esta cuenta y con esta fecha cae en el período cerrado?
     * Solo aplica a la caja chica: las demás cuentas no tienen candado.
     *
     * @param int    $account_id
     * @param string $movement_date 'Y-m-d' o 'Y-m-d H:i:s'
     */
    public static function is_locked($account_id, $movement_date)
    {
        $caja = self::account_id();
        if ($caja <= 0 || (int) $account_id !== $caja) {
            return false;
        }
        $lock = self::locked_until();
        if ($lock === '') {
            return false;
        }
        $day = substr(trim((string) $movement_date), 0, 10);
        // Comparación lexicográfica: válida para 'Y-m-d' (formato ordenable).
        return $day !== '' && $day <= $lock;
    }

    /** Primer día que SÍ admite movimientos ('Y-m-d'), o '' si no hay cierre. */
    public static function first_open_date()
    {
        $lock = self::locked_until();
        if ($lock === '') {
            return '';
        }
        $ts = strtotime($lock . ' +1 day');
        return $ts ? wp_date('Y-m-d', $ts) : '';
    }

    /**
     * Mensaje de error único para todo lo que el candado rechaza. Se usa en los
     * guards de FIN_Movements para que el usuario siempre lea lo mismo y sepa
     * exactamente cuál es la primera fecha válida.
     */
    public static function locked_error($what = 'este movimiento')
    {
        return new WP_Error('fin_cash_locked', sprintf(
            'La caja chica está rendida hasta el %s: no se puede registrar ni anular %s con esa fecha o anterior. La primera fecha válida es el %s.',
            mysql2date('d/m/Y', self::locked_until() . ' 12:00:00'),
            $what,
            mysql2date('d/m/Y', self::first_open_date() . ' 12:00:00')
        ));
    }

    /**
     * Reubica la fecha de un egreso AUTOMÁTICO que caería dentro del período
     * cerrado, al primer día abierto.
     *
     * Aplica a un caso real y raro: un pedido que se vuelve elegible DESPUÉS del
     * cierre (estaba cancelado y se restauró, o le cambiaron el método de envío) y
     * cuya fecha de creación es anterior al corte. Rechazar su egreso perdería
     * plata que sí salió de la caja; registrarlo con su fecha original rompería el
     * período cerrado. Se registra en el primer día abierto, con la fecha real
     * anotada en la descripción para que la reubicación sea auditable.
     *
     * @return array [string $date, string $note]  $note = '' si no hubo reubicación.
     */
    public static function relocate_if_locked($account_id, $movement_date)
    {
        if (!self::is_locked($account_id, $movement_date)) {
            return [$movement_date, ''];
        }
        $open = self::first_open_date();
        if ($open === '') {
            return [$movement_date, ''];
        }
        $real = substr(trim((string) $movement_date), 0, 10);
        return [
            $open . ' 00:00:00',
            sprintf(' [reubicado: caja cerrada, fecha real %s]', mysql2date('d/m/Y', $real . ' 12:00:00')),
        ];
    }

    // ── Bloqueos: egresos de envío sin validar ───────────────────────────────

    /**
     * Egresos de envío PENDIENTES con fecha <= corte: los que impiden cerrar.
     *
     * La ventana a escanear va del día siguiente al último cierre hasta el corte.
     * No es solo una optimización: `FIN_Orders::shipping_day_orders()` hace
     * `wc_get_orders(['limit' => -1, 'return' => 'objects'])` — cargar objetos de
     * pedido en masa agota la memoria de PHP en producción. El propio cierre acota
     * la ventana: todo lo anterior ya se validó (este mismo bloqueo lo exigió).
     *
     * @param string $cutoff 'Y-m-d'
     * @return array ['count'=>int, 'total'=>float, 'from'=>string, 'to'=>string,
     *                'days'=>array] — `days` es el detalle de shipping_day_orders().
     */
    public static function blockers($cutoff)
    {
        $cutoff = substr(trim((string) $cutoff), 0, 10);
        $empty  = ['count' => 0, 'total' => 0.0, 'from' => '', 'to' => $cutoff, 'days' => []];

        if (self::account_id() <= 0 || !FIN_Orders::shipping_configured() || $cutoff === '') {
            return $empty;
        }

        // Desde el día siguiente a la última rendición; si no hay ninguna, desde el
        // primer movimiento de la caja (el histórico abierto).
        $from = self::first_open_date();
        if ($from === '') {
            $from = self::first_movement_day() ?: $cutoff;
        }

        // Nunca antes del ARRANQUE del sistema. Los pedidos anteriores al corte se
        // saldaron fuera de Finanzas: no llevan egreso y no son pendientes. Sin
        // esto, esos pedidos legacy bloquearían la rendición para siempre (no se
        // pueden validar sin inventar egresos con fecha vieja que hundirían la
        // caja). Se configura en Configuración → Egreso de envío (courier).
        $hide_before = FIN_Orders::ship_hide_before();
        if ($hide_before !== '' && $from < $hide_before) {
            $from = $hide_before;
        }

        if ($from > $cutoff) {
            return $empty; // ventana vacía
        }

        // shipping_day_orders() ya devuelve SOLO los días con pendientes, y por día
        // trae pending_count / pending_total (un pedido sin costo cargado cuenta
        // como pendiente: es la señal de que falta el monto).
        $days  = FIN_Orders::shipping_day_orders($from, $cutoff);
        $count = 0;
        $total = 0.0;
        foreach ($days as $day) {
            $count += (int) $day['pending_count'];
            $total += (float) $day['pending_total'];
        }

        return [
            'count' => $count,
            'total' => round($total, 2),
            'from'  => $from,
            'to'    => $cutoff,
            'days'  => $days,
        ];
    }

    /** Día del primer movimiento de la caja chica ('Y-m-d'), o '' si no tiene. */
    private static function first_movement_day()
    {
        global $wpdb;
        $t   = FIN_Schema::table('movements');
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(movement_date) FROM $t WHERE account_id = %d",
            self::account_id()
        ));
        return $val ? substr((string) $val, 0, 10) : '';
    }

    // ── Rendir ───────────────────────────────────────────────────────────────

    /** Saldo de la caja chica al corte, según el ledger. Es la deuda a recargar. */
    public static function balance_at($cutoff)
    {
        $map = FIN_Movements::balances_as_of($cutoff);
        return round((float) ($map[self::account_id()] ?? 0.0), 2);
    }

    /**
     * RINDE la caja chica hasta $cutoff. Exige: caja configurada, corte no futuro,
     * corte posterior a la rendición vigente y CERO egresos pendientes.
     *
     * @return array|WP_Error nuevo estado.
     */
    public static function close($cutoff)
    {
        if (self::account_id() <= 0) {
            return new WP_Error('fin_lock_account',
                'No hay una caja chica configurada. Defínela en Configuración → Egreso de envío (courier).');
        }

        $cutoff = substr(trim((string) $cutoff), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff)) {
            return new WP_Error('fin_lock_date', 'Indica una fecha de rendición válida.');
        }
        if ($cutoff > wp_date('Y-m-d')) {
            return new WP_Error('fin_lock_future', 'La fecha de rendición no puede ser futura.');
        }

        $state = self::state();
        $lock  = (string) $state['date'];
        if ($lock !== '' && $cutoff <= $lock) {
            return new WP_Error('fin_lock_closed', sprintf(
                'La caja ya está rendida hasta el %s. La nueva rendición debe ser posterior.',
                mysql2date('d/m/Y', $lock . ' 12:00:00')
            ));
        }

        $blockers = self::blockers($cutoff);
        if ((int) $blockers['count'] > 0) {
            return new WP_Error('fin_lock_pending', sprintf(
                'No se puede rendir: hay %d egreso(s) de envío sin validar con fecha igual o anterior al corte. Valídalos abajo antes de rendir; si no, el saldo de la caja queda inflado y repondrías un monto equivocado. (Si son pedidos anteriores al arranque del sistema, fija el corte del panel en Configuración → Egreso de envío (courier).)',
                (int) $blockers['count']
            ));
        }

        // Historial: la rendición vigente pasa a la pila antes de ser reemplazada.
        // Es solo registro (no hay reapertura que lo consuma).
        $history = is_array($state['history']) ? $state['history'] : [];
        if ($lock !== '') {
            array_unshift($history, [
                'date'    => $lock,
                'by'      => (int) $state['by'],
                'at'      => (string) $state['at'],
                'balance' => (float) $state['balance'],
            ]);
            $history = array_slice($history, 0, self::HISTORY_MAX);
        }

        $new = [
            'date'    => $cutoff,
            'by'      => get_current_user_id(),
            'at'      => current_time('mysql'),
            'balance' => self::balance_at($cutoff),
            'history' => $history,
        ];
        update_option(self::OPTION, $new, false);
        return $new;
    }
}
