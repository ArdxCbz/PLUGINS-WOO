<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ledger central de movimientos financieros (v1.0).
 *
 * Reglas:
 *  - Un movimiento es INMUTABLE: nunca se edita ni se borra. Para corregir se
 *    crea un contrasiento (movimiento espejo) con reverse().
 *  - `balance_after` es el saldo de la CUENTA tras este movimiento. El saldo
 *    denormalizado (accounts.balance) y el balance_after se mueven juntos:
 *    FIN_Accounts::apply_delta() actualiza la cuenta y devuelve el nuevo saldo,
 *    que se graba en balance_after. Esa es la única fuente que escribe balance.
 *  - Egresos (y la pata `transfer_out`) validan saldo suficiente salvo que la
 *    cuenta tenga allow_negative=1 o se pase skip_balance_check (hechos ya
 *    consumados, p.ej. la integración de compras).
 *  - ingreso/egreso EXIGEN category_id válida cuya nature coincida con el tipo.
 *
 * Las direcciones: ingreso/transfer_in/opening = 'I' (suman); egreso/
 * transfer_out = 'O' (restan).
 */
class FIN_Movements
{
    /** Valores canónicos de `ref_table` (traza al documento de origen). */
    // LEGADO: la integración con Compras se retiró; ya no se generan movimientos
    // con este ref_table, pero puede haber registros históricos en la BD.
    const REF_PURCHASE       = 'purchases';
    const REF_ORDER_DEPOSIT  = 'order_deposit';
    const REF_ORDER_SHIPPING = 'order_shipping';
    // Pago mensual de costo de envío IBEX (un egreso por mes; ref_id = AAAAMM).
    const REF_ORDER_IBEX     = 'order_shipping_ibex';

    /** type => direction. */
    const DIRECTIONS = [
        'opening'      => 'I',
        'ingreso'      => 'I',
        'egreso'       => 'O',
        'transfer_in'  => 'I',
        'transfer_out' => 'O',
    ];

    public static function get($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        global $wpdb;
        $t = FIN_Schema::table('movements');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id), ARRAY_A) ?: null;
    }

    /**
     * Registra un movimiento simple (ingreso o egreso) y mueve el saldo de la
     * cuenta. Pensado para uso directo desde la UI y desde la integración.
     *
     *   $args:
     *     account_id        int    (requerido)
     *     type              'ingreso'|'egreso' (requerido)
     *     amount            float  (> 0, requerido)
     *     category_id       int    (requerido en ingreso/egreso)
     *     description       string
     *     movement_date     'Y-m-d H:i:s' | 'Y-m-d' (default: ahora)
     *     ref_table/ref_id/ref_code  trazabilidad opcional
     *     skip_balance_check bool   (omite validación de saldo; hechos consumados)
     *     user_id           int    (default: usuario actual)
     *
     * @return int|WP_Error id del movimiento.
     */
    public static function register(array $args)
    {
        $args = array_merge([
            'account_id'         => 0,
            'type'               => '',
            'amount'             => 0,
            'category_id'        => 0,
            'description'        => '',
            'movement_date'      => '',
            'rate'               => 0,
            'ref_table'          => null,
            'ref_id'             => null,
            'ref_code'           => null,
            'skip_balance_check' => false,
            'user_id'            => 0,
        ], $args);

        $type = (string) $args['type'];
        if (!in_array($type, ['ingreso', 'egreso'], true)) {
            return new WP_Error('fin_mov_type', 'Tipo de movimiento inválido. Use ingreso o egreso.');
        }

        $account = FIN_Accounts::get($args['account_id']);
        if (!$account) {
            return new WP_Error('fin_mov_account', 'La cuenta indicada no existe.');
        }

        $amount = round((float) $args['amount'], 2);
        if ($amount <= 0) {
            return new WP_Error('fin_mov_amount', 'El monto debe ser mayor a 0.');
        }

        // Moneda de la cuenta + tipo de cambio del movimiento. La base usa TC=1;
        // en monedas no-base se exige el TC (Bs por unidad) que se guarda en el
        // movimiento. La integración (skip_balance_check) que opera en base no
        // necesita pasar TC.
        $currency = (string) $account['currency'];
        $is_base  = FIN_Currencies::is_base($currency);
        $rate     = $is_base ? 1.0 : round((float) $args['rate'], 6);
        if (!$is_base && $rate <= 0) {
            return new WP_Error('fin_mov_rate', sprintf(
                'Indica el tipo de cambio (Bs por %s) de este movimiento.', FIN_Currencies::symbol($currency)
            ));
        }

        // Categoría obligatoria y con naturaleza coherente.
        $category = FIN_Categories::get($args['category_id']);
        if (!$category) {
            return new WP_Error('fin_mov_category', 'Debe seleccionar una categoría.');
        }
        if ($category['nature'] !== $type) {
            return new WP_Error(
                'fin_mov_category_nature',
                sprintf('La categoría "%s" es de tipo %s y no aplica a un %s.',
                    $category['name'], FIN_Categories::nature_label($category['nature']), $type)
            );
        }

        // Motivos tipo "Otros" (requires_description=1) exigen descripción.
        if (!empty($category['requires_description']) && trim((string) $args['description']) === '') {
            return new WP_Error(
                'fin_mov_description_required',
                sprintf('El motivo "%s" requiere una descripción.', $category['name'])
            );
        }

        $signed = ($type === 'egreso') ? -$amount : $amount;

        // Validación de saldo en egresos (salvo sobregiro permitido o hecho consumado).
        if ($type === 'egreso' && empty($args['skip_balance_check']) && (int) $account['allow_negative'] !== 1) {
            if ((float) $account['balance'] < $amount) {
                return new WP_Error(
                    'fin_mov_insufficient',
                    sprintf('Saldo insuficiente en "%s" (disponible %s, requerido %s).',
                        $account['name'],
                        fin_money($account['balance'], $currency),
                        fin_money($amount, $currency))
                );
            }
        }

        // insert() mueve el saldo de la cuenta (apply_delta) Y luego inserta la
        // fila. Envolvemos ambos pasos en una transacción: si el insert falla, el
        // movimiento del saldo se revierte y no quedan cuenta y ledger descuadrados.
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        $r = self::insert([
            'account_id'    => (int) $account['id'],
            'category_id'   => (int) $category['id'],
            'type'          => $type,
            'amount'        => $amount,
            'signed'        => $signed,
            'currency'      => $currency,
            'rate_to_base'  => $rate,
            'description'   => (string) $args['description'],
            'movement_date' => self::normalize_date($args['movement_date']),
            'ref_table'     => $args['ref_table'],
            'ref_id'        => $args['ref_id'],
            'ref_code'      => $args['ref_code'],
            'user_id'       => (int) ($args['user_id'] ?: get_current_user_id()),
        ]);
        if (is_wp_error($r)) {
            $wpdb->query('ROLLBACK');
            return $r;
        }
        $wpdb->query('COMMIT');
        return $r;
    }

    /**
     * Siembra el movimiento de apertura de una cuenta recién creada. Lo llama
     * el endpoint tras FIN_Accounts::save() cuando opening_balance != 0. NO
     * vuelve a mover el saldo (save() ya fijó balance = opening_balance); solo
     * deja el asiento `opening` con balance_after = opening_balance.
     *
     * El saldo de apertura puede ser negativo (cuenta deudora desde el inicio):
     * en ese caso el asiento se registra como egreso (direction='O', amount es
     * la magnitud) y balance_after queda en el saldo firmado.
     *
     * @return int|WP_Error id del movimiento opening.
     */
    public static function register_opening($account_id, $opening_balance, $user_id = 0)
    {
        $opening = round((float) $opening_balance, 2);
        if ($opening == 0.0) {
            return 0; // nada que sembrar
        }
        $account = FIN_Accounts::get($account_id);
        if (!$account) {
            return new WP_Error('fin_mov_account', 'La cuenta indicada no existe.');
        }

        $direction = $opening >= 0 ? 'I' : 'O';
        $magnitude = abs($opening);
        $currency  = (string) $account['currency'];
        $rate      = FIN_Currencies::rate($currency); // base = 1

        global $wpdb;
        $t = FIN_Schema::table('movements');
        $ok = $wpdb->insert($t, [
            'account_id'    => (int) $account['id'],
            'category_id'   => null,
            'type'          => 'opening',
            'direction'     => $direction,
            'currency'      => $currency,
            'rate_to_base'  => $rate,
            'amount'        => $magnitude,
            'balance_after' => $opening,
            'description'   => 'Saldo de apertura',
            'movement_date' => current_time('mysql'),
            'user_id'       => (int) ($user_id ?: get_current_user_id()),
            'created_at'    => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%d', '%s']);

        if (!$ok) {
            return new WP_Error('fin_db_insert', 'No se pudo registrar la apertura.');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Transferencia atómica entre dos cuentas. Crea dos movimientos unidos por
     * un transfer_id (la pata `transfer_out` en el origen y `transfer_in` en el
     * destino). Valida saldo en el origen salvo allow_negative.
     *
     * MULTI-MONEDA (2.3+): `amount` es el monto en la moneda de la cuenta de
     * ORIGEN. Si origen y destino tienen distinta moneda, `rate` es el tipo de
     * cambio (Bs por unidad de la moneda NO base) y el monto que llega al destino
     * se calcula vía la moneda base. Cada pata guarda su moneda y su monto propio.
     *
     *   $args: from_account_id, to_account_id, amount, rate, description, movement_date
     *
     * @return array|WP_Error ['out_id'=>int, 'in_id'=>int, 'transfer_id'=>int]
     */
    public static function transfer(array $args)
    {
        $from_id = (int) ($args['from_account_id'] ?? 0);
        $to_id   = (int) ($args['to_account_id'] ?? 0);
        $amount  = round((float) ($args['amount'] ?? 0), 2);
        $rate_in = round((float) ($args['rate'] ?? 0), 6);
        $desc    = (string) ($args['description'] ?? '');
        $date    = self::normalize_date($args['movement_date'] ?? '');
        $user_id = (int) (($args['user_id'] ?? 0) ?: get_current_user_id());

        if ($from_id === $to_id) {
            return new WP_Error('fin_tr_same', 'La cuenta origen y destino deben ser distintas.');
        }
        if ($amount <= 0) {
            return new WP_Error('fin_tr_amount', 'El monto debe ser mayor a 0.');
        }

        $from = FIN_Accounts::get($from_id);
        $to   = FIN_Accounts::get($to_id);
        if (!$from || !$to) {
            return new WP_Error('fin_tr_account', 'Cuenta origen o destino inexistente.');
        }
        if ((int) $from['allow_negative'] !== 1 && (float) $from['balance'] < $amount) {
            return new WP_Error(
                'fin_tr_insufficient',
                sprintf('Saldo insuficiente en "%s" (disponible %s, requerido %s).',
                    $from['name'],
                    fin_money($from['balance'], $from['currency']),
                    fin_money($amount, $from['currency']))
            );
        }

        // Conversión de monedas. Tasa efectiva (Bs por unidad) de cada pata: la
        // base vale 1; la no base toma el TC ingresado cuando la otra pata es base
        // (caso típico Bs↔$); si ambas son no base, cada una usa su TC por defecto.
        $cf = (string) $from['currency'];
        $ct = (string) $to['currency'];
        $cf_base = FIN_Currencies::is_base($cf);
        $ct_base = FIN_Currencies::is_base($ct);

        if ($cf === $ct) {
            $rate_from = FIN_Currencies::rate($cf); // misma moneda: sin conversión
            $rate_to   = $rate_from;
        } elseif (!$cf_base && $ct_base) {
            $rate_from = $rate_in > 0 ? $rate_in : FIN_Currencies::rate($cf);
            $rate_to   = 1.0;
        } elseif ($cf_base && !$ct_base) {
            $rate_from = 1.0;
            $rate_to   = $rate_in > 0 ? $rate_in : FIN_Currencies::rate($ct);
        } else {
            // Ambas no base: sin TC manual fiable, se usan los TC por defecto.
            $rate_from = FIN_Currencies::rate($cf);
            $rate_to   = FIN_Currencies::rate($ct);
        }

        if ($cf !== $ct && ($rate_from <= 0 || $rate_to <= 0)) {
            return new WP_Error('fin_tr_rate',
                'Indica un tipo de cambio válido para la transferencia entre monedas.');
        }

        // Monto que llega al destino = (monto origen × tasa origen) / tasa destino.
        $amount_to = ($cf === $ct)
            ? $amount
            : round(($amount * $rate_from) / $rate_to, 2);
        if ($amount_to <= 0) {
            return new WP_Error('fin_tr_amount_to', 'El monto convertido al destino resultó en 0. Revisa el tipo de cambio.');
        }

        global $wpdb;
        $t = FIN_Schema::table('movements');

        $wpdb->query('START TRANSACTION');

        // Pata de salida (origen) — en la moneda del origen.
        $out = self::insert([
            'account_id'    => $from_id,
            'category_id'   => null,
            'type'          => 'transfer_out',
            'amount'        => $amount,
            'signed'        => -$amount,
            'currency'      => $cf,
            'rate_to_base'  => $rate_from,
            'description'   => $desc !== '' ? $desc : ('Transferencia a ' . $to['name']),
            'movement_date' => $date,
            'user_id'       => $user_id,
        ]);
        if (is_wp_error($out)) {
            $wpdb->query('ROLLBACK');
            return $out;
        }

        // Pata de entrada (destino) — en la moneda del destino, monto convertido.
        $in = self::insert([
            'account_id'    => $to_id,
            'category_id'   => null,
            'type'          => 'transfer_in',
            'amount'        => $amount_to,
            'signed'        => $amount_to,
            'currency'      => $ct,
            'rate_to_base'  => $rate_to,
            'description'   => $desc !== '' ? $desc : ('Transferencia de ' . $from['name']),
            'movement_date' => $date,
            'user_id'       => $user_id,
        ]);
        if (is_wp_error($in)) {
            $wpdb->query('ROLLBACK');
            return $in;
        }

        // Enlazar ambas patas con un transfer_id común (= id de la pata de salida).
        $transfer_id = (int) $out;
        $wpdb->update($t, ['transfer_id' => $transfer_id], ['id' => (int) $out], ['%d'], ['%d']);
        $wpdb->update($t, ['transfer_id' => $transfer_id], ['id' => (int) $in],  ['%d'], ['%d']);

        $wpdb->query('COMMIT');

        return ['out_id' => (int) $out, 'in_id' => (int) $in, 'transfer_id' => $transfer_id];
    }

    /**
     * Anula un movimiento creando un contrasiento (movimiento espejo de
     * dirección opuesta) y marca el original como anulado. No edita ni borra
     * el original. No se pueden anular:
     *  - un movimiento ya anulado,
     *  - un contrasiento (un movimiento que ya es reversión de otro),
     *  - una pata de transferencia (se debe anular la transferencia completa,
     *    fuera de alcance de v1: se sugiere registrar la transferencia inversa).
     *
     * @return int|WP_Error id del contrasiento.
     */
    public static function reverse($id, $user_id = 0)
    {
        $mov = self::get($id);
        if (!$mov) {
            return new WP_Error('fin_rev_not_found', 'Movimiento no encontrado.');
        }
        if (!empty($mov['reversed_at'])) {
            return new WP_Error('fin_rev_already', 'Este movimiento ya fue anulado.');
        }
        if (!empty($mov['reverses_id'])) {
            return new WP_Error('fin_rev_is_reversal', 'No se puede anular un contrasiento.');
        }
        if (in_array($mov['type'], ['transfer_in', 'transfer_out'], true)) {
            return new WP_Error('fin_rev_transfer', 'Para revertir una transferencia, registre la transferencia inversa.');
        }
        if ($mov['type'] === 'opening') {
            return new WP_Error('fin_rev_opening', 'El saldo de apertura no se puede anular.');
        }

        // El contrasiento invierte la dirección: un ingreso se anula con un
        // egreso por el mismo monto, y viceversa. Salta validación de saldo
        // (corrección administrativa).
        $opposite = ($mov['type'] === 'ingreso') ? 'egreso' : 'ingreso';
        $signed   = ($opposite === 'egreso') ? -(float) $mov['amount'] : (float) $mov['amount'];

        global $wpdb;
        $t = FIN_Schema::table('movements');

        $wpdb->query('START TRANSACTION');

        $rev = self::insert([
            'account_id'    => (int) $mov['account_id'],
            'category_id'   => $mov['category_id'] !== null ? (int) $mov['category_id'] : null,
            'type'          => $opposite,
            'amount'        => (float) $mov['amount'],
            'signed'        => $signed,
            'currency'      => (string) ($mov['currency'] ?? FIN_Currencies::BASE_CODE),
            'rate_to_base'  => (float) ($mov['rate_to_base'] ?? 1),
            'description'   => 'Anulación del movimiento #' . (int) $mov['id'],
            'movement_date' => current_time('mysql'),
            'reverses_id'   => (int) $mov['id'],
            'user_id'       => (int) ($user_id ?: get_current_user_id()),
        ]);
        if (is_wp_error($rev)) {
            $wpdb->query('ROLLBACK');
            return $rev;
        }

        $wpdb->update($t,
            ['reversed_at' => current_time('mysql'), 'reversed_by' => (int) ($user_id ?: get_current_user_id())],
            ['id' => (int) $mov['id']],
            ['%s', '%d'],
            ['%d']
        );

        $wpdb->query('COMMIT');
        return (int) $rev;
    }

    /**
     * Inserta una fila de movimiento y mueve el saldo de la cuenta. Bajo nivel:
     * asume que la validación ya ocurrió. `$args['signed']` es la variación
     * firmada del saldo; `$args['amount']` siempre positivo.
     *
     * @return int|WP_Error id insertado.
     */
    private static function insert(array $args)
    {
        global $wpdb;
        $t = FIN_Schema::table('movements');

        $type      = (string) $args['type'];
        $direction = self::DIRECTIONS[$type] ?? 'I';

        // Mover saldo de la cuenta y obtener el balance resultante.
        $new_balance = FIN_Accounts::apply_delta((int) $args['account_id'], (float) $args['signed']);
        if (is_wp_error($new_balance)) {
            return $new_balance;
        }

        $ok = $wpdb->insert($t, [
            'account_id'    => (int) $args['account_id'],
            'category_id'   => isset($args['category_id']) && $args['category_id'] !== null ? (int) $args['category_id'] : null,
            'type'          => $type,
            'direction'     => $direction,
            'currency'      => (string) ($args['currency'] ?? FIN_Currencies::BASE_CODE),
            'rate_to_base'  => round((float) ($args['rate_to_base'] ?? 1), 6),
            'amount'        => round((float) $args['amount'], 2),
            'balance_after' => round((float) $new_balance, 2),
            'description'   => sanitize_textarea_field((string) ($args['description'] ?? '')),
            'movement_date' => (string) $args['movement_date'],
            'reverses_id'   => isset($args['reverses_id']) ? (int) $args['reverses_id'] : null,
            'ref_table'     => isset($args['ref_table']) && $args['ref_table'] !== null ? (string) $args['ref_table'] : null,
            'ref_id'        => isset($args['ref_id']) && $args['ref_id'] !== null ? (int) $args['ref_id'] : null,
            'ref_code'      => isset($args['ref_code']) && $args['ref_code'] !== null ? (string) $args['ref_code'] : null,
            'user_id'       => (int) ($args['user_id'] ?? 0),
            'created_at'    => current_time('mysql'),
        ], [
            '%d', // account_id
            '%d', // category_id
            '%s', // type
            '%s', // direction
            '%s', // currency
            '%f', // rate_to_base
            '%f', // amount
            '%f', // balance_after
            '%s', // description
            '%s', // movement_date
            '%d', // reverses_id
            '%s', // ref_table
            '%d', // ref_id
            '%s', // ref_code
            '%d', // user_id
            '%s', // created_at
        ]);

        if (!$ok) {
            return new WP_Error('fin_db_insert', 'No se pudo registrar el movimiento.');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Consulta movimientos con filtros para el listado y los exports.
     *
     *   $args: account_id, category_id, type, from (Y-m-d), to (Y-m-d),
     *          search (en description), limit, offset (paginación)
     */
    /**
     * Construye el WHERE de filtros del listado/totales. Devuelve [where[], params[]].
     * Filtros: account_id, category_id, type, from, to, search.
     */
    private static function build_filter_where(array $args)
    {
        global $wpdb;
        $where  = ['1=1'];
        $params = [];

        if (!empty($args['account_id'])) {
            $where[]  = 'account_id = %d';
            $params[] = (int) $args['account_id'];
        }
        if (!empty($args['category_id'])) {
            $where[]  = 'category_id = %d';
            $params[] = (int) $args['category_id'];
        }
        if (!empty($args['type'])) {
            $where[]  = 'type = %s';
            $params[] = (string) $args['type'];
        }
        if (!empty($args['currency'])) {
            $where[]  = 'currency = %s';
            $params[] = strtoupper((string) $args['currency']);
        }
        if (!empty($args['from'])) {
            $where[]  = 'movement_date >= %s';
            $params[] = self::normalize_date($args['from'], '00:00:00');
        }
        if (!empty($args['to'])) {
            $where[]  = 'movement_date <= %s';
            $params[] = self::normalize_date($args['to'], '23:59:59');
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = '(description LIKE %s OR ref_code LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        return [$where, $params];
    }

    public static function query(array $args = [])
    {
        global $wpdb;
        $t = FIN_Schema::table('movements');

        list($where, $params) = self::build_filter_where($args);

        // Orden con whitelist (no interpolar entrada cruda en SQL). Columnas
        // permitidas y dirección; el id queda de desempate estable.
        $allowed_orderby = ['movement_date', 'id', 'amount'];
        $orderby = (isset($args['orderby']) && in_array($args['orderby'], $allowed_orderby, true))
            ? $args['orderby'] : 'movement_date';
        $order = (isset($args['order']) && strtoupper((string) $args['order']) === 'ASC') ? 'ASC' : 'DESC';
        $order_sql = ($orderby === 'id') ? "id $order" : "$orderby $order, id $order";

        // limit por defecto 500; un limit <= 0 explícito = sin límite (exports).
        $limit  = array_key_exists('limit', $args) ? (int) $args['limit'] : 500;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where)
             . " ORDER BY $order_sql";
        if ($limit > 0) {
            $sql .= " LIMIT " . $limit;
            if ($offset > 0) {
                $sql .= " OFFSET " . $offset;
            }
        }
        $sql = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Totales del conjunto FILTRADO (sin límite) DESGLOSADOS POR MONEDA: ingresos
     * (type=ingreso), egresos (type=egreso), neto = ing − egr. Multi-moneda: no
     * se consolida, cada moneda lleva su propio total. Usa los mismos filtros que
     * query().
     *
     * @return array ['count'=>int, 'by_currency'=>[code => ['ingresos','egresos','neto']]]
     */
    public static function filtered_totals(array $args)
    {
        global $wpdb;
        $t = FIN_Schema::table('movements');
        list($where, $params) = self::build_filter_where($args);

        $sql = "SELECT currency,
                    COALESCE(SUM(CASE WHEN type = 'ingreso' THEN amount ELSE 0 END), 0) AS ingresos,
                    COALESCE(SUM(CASE WHEN type = 'egreso'  THEN amount ELSE 0 END), 0) AS egresos,
                    COUNT(*) AS n
                FROM $t WHERE " . implode(' AND ', $where) . "
                GROUP BY currency ORDER BY currency ASC";
        $sql  = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $by_currency = [];
        $count = 0;
        foreach ($rows as $r) {
            $ing = (float) $r['ingresos'];
            $egr = (float) $r['egresos'];
            $by_currency[(string) $r['currency']] = [
                'ingresos' => round($ing, 2),
                'egresos'  => round($egr, 2),
                'neto'     => round($ing - $egr, 2),
            ];
            $count += (int) $r['n'];
        }
        return ['count' => $count, 'by_currency' => $by_currency];
    }

    /**
     * Saldo de cada cuenta AL CORTE de una fecha (incluye todo movimiento con
     * movement_date <= fin de ese día). Si $to es vacío, equivale al saldo
     * actual. Saldo de cuenta = Σ(direction firmada).
     *
     * @return array [account_id => saldo float]
     */
    public static function balances_as_of($to = '')
    {
        global $wpdb;
        $t = FIN_Schema::table('movements');
        $where  = '1=1';
        $params = [];
        if (!empty($to)) {
            $where   .= ' AND movement_date <= %s';
            $params[] = self::normalize_date($to, '23:59:59');
        }
        $sql = "SELECT account_id,
                       SUM(CASE WHEN direction = 'I' THEN amount ELSE -amount END) AS bal
                FROM $t WHERE $where GROUP BY account_id";
        $sql = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['account_id']] = round((float) $r['bal'], 2);
        }
        return $map;
    }

    /**
     * Mapa id => SALDO GENERAL corrido tras ese movimiento, POR MONEDA (Σ
     * acumulada firmada del ledger de esa misma moneda, en orden cronológico).
     * Multi-moneda (2.3+): no se mezclan monedas; cada movimiento lleva el
     * acumulado de su propia moneda. Se indexa por id, así el valor es correcto
     * para cualquier filtro u orden.
     *
     * @return array [id => saldo_general_de_su_moneda float]
     */
    public static function running_general_map()
    {
        global $wpdb;
        $t = FIN_Schema::table('movements');
        $rows = $wpdb->get_results(
            "SELECT id, direction, amount, currency FROM $t ORDER BY movement_date ASC, id ASC",
            ARRAY_A
        ) ?: [];

        $map = [];
        $run = []; // por moneda
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            if (!isset($run[$cur])) {
                $run[$cur] = 0.0;
            }
            $run[$cur] += ($r['direction'] === 'I') ? (float) $r['amount'] : -(float) $r['amount'];
            $map[(int) $r['id']] = round($run[$cur], 2);
        }
        return $map;
    }

    /**
     * Movimiento ORIGINAL (no contrasiento) de una referencia, o null. Para leer
     * monto/fecha de un egreso ya registrado (p.ej. el pago mensual de IBEX).
     */
    public static function get_by_ref($ref_table, $ref_id)
    {
        global $wpdb;
        $t = FIN_Schema::table('movements');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE ref_table = %s AND ref_id = %d AND reverses_id IS NULL ORDER BY id DESC LIMIT 1",
            (string) $ref_table, (int) $ref_id
        ), ARRAY_A) ?: null;
    }

    /**
     * Monto NETO VIGENTE ya registrado en el ledger para una referencia (suma
     * firmada por dirección: ingresos suman, egresos restan). Sirve para la
     * reconciliación incremental: cuántos Bs siguen ingresados por este documento,
     * para registrar solo el delta pendiente.
     *
     * EXCLUYE los movimientos **anulados** (`reversed_at` no nulo) y los propios
     * **contrasientos** (`reverses_id` no nulo). Esto importa porque `reverse()`
     * NO copia `ref_table`/`ref_id` al contrasiento: si no se filtrara el original
     * anulado, seguiría contando como "ya ingresado" y la reconciliación nunca
     * volvería a registrar tras una anulación. Al excluirlo, anular un ingreso
     * baja el neto vigente y el siguiente depósito/completado re-registra el delta.
     *
     * @return float neto en la moneda de los movimientos (se asume una sola caja).
     */
    public static function posted_amount_for_ref($ref_table, $ref_id)
    {
        global $wpdb;
        $t = FIN_Schema::table('movements');
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'I' THEN amount ELSE -amount END), 0)
             FROM $t
             WHERE ref_table = %s AND ref_id = %d
               AND reverses_id IS NULL
               AND reversed_at IS NULL",
            (string) $ref_table, (int) $ref_id
        ));
        return round((float) $val, 2);
    }

    /** ¿Ya existe un movimiento para esta referencia? (idempotencia integración). */
    public static function exists_for_ref($ref_table, $ref_id)
    {
        global $wpdb;
        $t = FIN_Schema::table('movements');
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $t WHERE ref_table = %s AND ref_id = %d LIMIT 1",
            (string) $ref_table, (int) $ref_id
        )) === 1;
    }

    /**
     * Versión por lotes de exists_for_ref: dado un conjunto de $ref_ids, devuelve
     * los que YA tienen un movimiento con ese $ref_table. Evita el N+1 cuando se
     * chequean muchos documentos a la vez (p.ej. el panel diario de envíos).
     *
     * @return int[] subconjunto de $ref_ids que ya existen.
     */
    public static function existing_refs($ref_table, array $ref_ids)
    {
        $ref_ids = array_values(array_unique(array_filter(array_map('intval', $ref_ids), function ($id) {
            return $id > 0;
        })));
        if (empty($ref_ids)) {
            return [];
        }
        global $wpdb;
        $t = FIN_Schema::table('movements');
        $placeholders = implode(',', array_fill(0, count($ref_ids), '%d'));
        $params = array_merge([(string) $ref_table], $ref_ids);
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ref_id FROM $t WHERE ref_table = %s AND ref_id IN ($placeholders)",
            ...$params
        ));
        return array_map('intval', $rows);
    }

    /** Normaliza una fecha de entrada a 'Y-m-d H:i:s'. Vacío => ahora. */
    private static function normalize_date($value, $time_suffix = null)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return current_time('mysql');
        }
        // Solo fecha (Y-m-d): completar con hora.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            if ($time_suffix !== null) {
                return $value . ' ' . $time_suffix;
            }
            // Fecha sin hora desde un form: usar la hora actual para ordenar bien.
            return $value . ' ' . current_time('H:i:s');
        }
        // Asumir 'Y-m-d H:i:s' u otro formato datetime aceptable.
        return $value;
    }

    public static function type_label($type)
    {
        $labels = [
            'opening'      => 'Apertura',
            'ingreso'      => 'Ingreso',
            'egreso'       => 'Egreso',
            'transfer_in'  => 'Transfer. recibida',
            'transfer_out' => 'Transfer. enviada',
        ];
        return $labels[$type] ?? $type;
    }
}
