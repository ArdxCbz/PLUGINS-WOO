<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CRUD de cuentas financieras (bancos y cajas de efectivo) — v1.0.
 *
 *  - `name` es el único campo obligatorio.
 *  - `currency` (2.3+) es la moneda de la cuenta (código de FIN_Currencies,
 *    default base 'BOB'). El saldo (opening/balance) está en la moneda propia.
 *    Se puede elegir al crear y editar, pero queda BLOQUEADA una vez que la
 *    cuenta tiene movimientos (cambiarla descuadraría el histórico).
 *  - `type` ∈ {banco, efectivo}. Efectivo normalmente sin account_number.
 *  - `opening_balance` es FIJO tras crear. Al crear con saldo > 0 se siembra
 *    un movimiento `opening` (lo hace FIN_Movements desde el endpoint). Para
 *    ajustar el saldo después de creada la cuenta se usa un movimiento manual,
 *    nunca editando `opening_balance` (rompería el saldo corrido).
 *  - `balance` es el saldo actual denormalizado; SOLO lo mueve apply_delta()
 *    desde FIN_Movements. Nadie más debe escribirlo.
 *  - `active=0` es soft-disable: la cuenta sale de los selects de nuevos
 *    movimientos pero su histórico permanece. No hay hard-delete porque
 *    movements.account_id es FK lógica sin ON DELETE.
 */
class FIN_Accounts
{
    const TYPES = ['banco', 'efectivo'];

    /**
     * Mapa cuenta → motivos permitidos: option `fin_account_motivos` con forma
     * { account_id(int) : [category_id(int), ...] }. SEMÁNTICA ESTRICTA: una
     * cuenta sin entrada (o con lista vacía) NO permite registrar movimientos
     * manuales hasta que se le asignen motivos en Configuración. EXCLUSIVO: cada
     * motivo pertenece a una sola cuenta. NO afecta a las automatizaciones
     * (depósito/envío), que registran con su categoría configurada sin pasar por
     * este allowlist.
     */
    const OPT_MOTIVOS = 'fin_account_motivos';

    /** Mapa completo cuenta→motivos [account_id => int[]]. */
    public static function motivos_map()
    {
        $v = get_option(self::OPT_MOTIVOS, []);
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $aid => $ids) {
            if (!is_array($ids)) {
                continue;
            }
            $out[(int) $aid] = array_values(array_unique(array_map('intval', $ids)));
        }
        return $out;
    }

    /** Motivos permitidos (IDs) de una cuenta; [] si no tiene ninguno asignado. */
    public static function allowed_motivos($account_id)
    {
        $map = self::motivos_map();
        return $map[(int) $account_id] ?? [];
    }

    /** ¿El motivo $cat_id está permitido en la cuenta $account_id? (estricto). */
    public static function motivo_allowed($account_id, $cat_id)
    {
        return in_array((int) $cat_id, self::allowed_motivos($account_id), true);
    }

    /**
     * Mapa [category_id => account_id] de qué cuenta "posee" cada motivo.
     * Cada motivo pertenece a UNA sola cuenta (exclusividad). Opcionalmente
     * excluye una cuenta del mapa (útil para no marcarse a sí misma en la UI).
     */
    public static function motivo_owners($exclude_account_id = 0)
    {
        $exclude = (int) $exclude_account_id;
        $owners  = [];
        foreach (self::motivos_map() as $aid => $ids) {
            $aid = (int) $aid;
            if ($aid === $exclude) {
                continue;
            }
            foreach ($ids as $cid) {
                $owners[(int) $cid] = $aid;
            }
        }
        return $owners;
    }

    /**
     * Define los motivos permitidos de una cuenta (reemplaza la lista).
     * EXCLUSIVIDAD: un motivo no puede asignarse a dos cuentas distintas; si
     * alguno de los $cat_ids ya pertenece a otra cuenta, se rechaza todo.
     */
    public static function set_allowed_motivos($account_id, array $cat_ids)
    {
        $account_id = (int) $account_id;
        if ($account_id <= 0) {
            return new WP_Error('fin_account_motivos', 'Cuenta inválida.');
        }
        $map = self::motivos_map();
        $clean = array_values(array_unique(array_filter(array_map('intval', $cat_ids), function ($id) {
            return $id > 0;
        })));

        // Rechazar motivos ya tomados por otra cuenta (exclusividad).
        $owners = self::motivo_owners($account_id);
        $taken  = array_values(array_intersect($clean, array_keys($owners)));
        if (!empty($taken)) {
            $names = [];
            foreach ($taken as $cid) {
                $cat       = FIN_Categories::get($cid);
                $owner     = self::get($owners[$cid]);
                $cat_name  = $cat   ? $cat['name']   : ('#' . $cid);
                $owner_nm  = $owner ? $owner['name'] : ('#' . $owners[$cid]);
                $names[]   = sprintf('"%s" (ya en "%s")', $cat_name, $owner_nm);
            }
            return new WP_Error('fin_account_motivos_dup', sprintf(
                'Estos motivos ya están asignados a otra cuenta: %s. Un motivo solo puede pertenecer a una cuenta; quítalo de la otra primero.',
                implode(', ', $names)
            ));
        }

        if (empty($clean)) {
            unset($map[$account_id]); // sin entrada = sin motivos (estricto)
        } else {
            $map[$account_id] = $clean;
        }
        update_option(self::OPT_MOTIVOS, $map, false);
        return true;
    }

    /** Lista cuentas. */
    public static function query(array $args = [])
    {
        global $wpdb;
        $t = FIN_Schema::table('accounts');

        $where  = ['1=1'];
        $params = [];
        if (isset($args['active']) && $args['active'] !== '') {
            $where[]  = 'active = %d';
            $params[] = (int) $args['active'];
        }
        if (!empty($args['type'])) {
            $where[]  = 'type = %s';
            $params[] = (string) $args['type'];
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = '(name LIKE %s OR account_number LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : 500;
        $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where)
             . " ORDER BY active DESC, name ASC LIMIT $limit";
        $sql = $params ? $wpdb->prepare($sql, ...$params) : $sql;
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function get($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        global $wpdb;
        $t = FIN_Schema::table('accounts');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id), ARRAY_A) ?: null;
    }

    /** Solo activas — para selects de nuevos movimientos. */
    public static function active_list()
    {
        return self::query(['active' => 1, 'limit' => 1000]);
    }

    /**
     * Total tesorería de cuentas activas POR MONEDA (no se consolida).
     *
     * @return array [currency_code => saldo float]
     */
    public static function treasury_by_currency()
    {
        global $wpdb;
        $t = FIN_Schema::table('accounts');
        $rows = $wpdb->get_results(
            "SELECT currency, COALESCE(SUM(balance),0) AS bal FROM $t WHERE active = 1 GROUP BY currency",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['currency']] = round((float) $r['bal'], 2);
        }
        return $out;
    }

    /** Suma de saldos de las cuentas activas en la moneda BASE (compat). */
    public static function treasury_total()
    {
        global $wpdb;
        $t = FIN_Schema::table('accounts');
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(balance),0) FROM $t WHERE active = 1 AND currency = %s",
            FIN_Currencies::BASE_CODE
        ));
    }

    /** ¿La cuenta ya tiene movimientos? (para bloquear el cambio de moneda). */
    public static function has_movements($account_id)
    {
        global $wpdb;
        $t = FIN_Schema::table('movements');
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE account_id = %d", (int) $account_id
        )) > 0;
    }

    /** ¿Alguna cuenta usa esta moneda? (para impedir borrarla del catálogo). */
    public static function currency_in_use($code)
    {
        global $wpdb;
        $t = FIN_Schema::table('accounts');
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE currency = %s", strtoupper((string) $code)
        )) > 0;
    }

    /**
     * Crea o actualiza una cuenta.
     *
     *   $args:
     *     id              int    (>0 = update; 0 = insert)
     *     name            string (requerido)
     *     account_number  string
     *     type            'banco'|'efectivo'
     *     opening_balance float  (solo aplica al crear; ignorado en update)
     *     allow_negative  0|1
     *     active          0|1
     *     notes           string
     *
     * NOTA: este método NO siembra el movimiento `opening`. El endpoint que
     * crea la cuenta debe, tras recibir el id nuevo, llamar a
     * FIN_Movements::register_opening() si opening_balance > 0.
     *
     * @return int|WP_Error id de la cuenta.
     */
    public static function save(array $args)
    {
        $args = array_merge([
            'id'              => 0,
            'name'            => '',
            'account_number'  => '',
            'type'            => 'banco',
            'currency'        => FIN_Currencies::BASE_CODE,
            'opening_balance' => 0,
            'allow_negative'  => 0,
            'active'          => 1,
            'notes'           => '',
        ], $args);

        $name = trim((string) $args['name']);
        if ($name === '') {
            return new WP_Error('fin_account_name_required', 'El nombre de la cuenta es obligatorio.');
        }
        if (mb_strlen($name) > 150) {
            return new WP_Error('fin_account_name_too_long', 'El nombre supera 150 caracteres.');
        }

        $type = in_array($args['type'], self::TYPES, true) ? $args['type'] : 'banco';

        $currency = strtoupper(trim((string) $args['currency']));
        if (!FIN_Currencies::exists($currency)) {
            $currency = FIN_Currencies::BASE_CODE;
        }

        $data = [
            'name'           => $name,
            'account_number' => sanitize_text_field((string) $args['account_number']),
            'type'           => $type,
            'allow_negative' => !empty($args['allow_negative']) ? 1 : 0,
            'active'         => !empty($args['active']) ? 1 : 0,
            'notes'          => wp_kses_post((string) $args['notes']),
            'updated_at'     => current_time('mysql'),
        ];
        $fmt = ['%s', '%s', '%s', '%d', '%d', '%s', '%s'];

        global $wpdb;
        $t = FIN_Schema::table('accounts');

        $id = (int) $args['id'];
        if ($id > 0) {
            // En update NO se toca opening_balance ni balance.
            $exists = self::get($id);
            if (!$exists) {
                return new WP_Error('fin_account_not_found', 'Cuenta no encontrada.');
            }
            // La moneda solo se puede cambiar si la cuenta aún no tiene movimientos.
            if (!self::has_movements($id)) {
                $data['currency'] = $currency;
                $fmt[] = '%s';
            }
            $ok = $wpdb->update($t, $data, ['id' => $id], $fmt, ['%d']);
            if ($ok === false) {
                return new WP_Error('fin_db_update', 'No se pudo actualizar la cuenta.');
            }
            return $id;
        }

        // Insert: el opening_balance fija el balance inicial. El movimiento
        // `opening` correspondiente lo crea el endpoint vía FIN_Movements.
        $opening = round((float) $args['opening_balance'], 2);
        $data['currency']        = $currency;
        $data['opening_balance'] = $opening;
        $data['balance']         = $opening;
        $data['created_at']      = current_time('mysql');
        // Una cuenta que abre con saldo deudor (negativo) implica permitir saldo
        // negativo; de lo contrario violaría su propia restricción desde el inicio.
        if ($opening < 0) {
            $data['allow_negative'] = 1;
        }
        $fmt[] = '%s'; // currency
        $fmt[] = '%f'; // opening_balance
        $fmt[] = '%f'; // balance
        $fmt[] = '%s'; // created_at

        $ok = $wpdb->insert($t, $data, $fmt);
        if (!$ok) {
            return new WP_Error('fin_db_insert', 'No se pudo crear la cuenta.');
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Aplica una variación firmada al saldo denormalizado de la cuenta y
     * devuelve el nuevo saldo. Uso EXCLUSIVO de FIN_Movements (dentro de la
     * misma transacción que inserta el movimiento). $signed es positivo para
     * ingresos y negativo para egresos.
     *
     * @return float|WP_Error nuevo balance.
     */
    public static function apply_delta($account_id, $signed)
    {
        global $wpdb;
        $t  = FIN_Schema::table('accounts');
        $id = (int) $account_id;

        $ok = $wpdb->query($wpdb->prepare(
            "UPDATE $t SET balance = balance + %f, updated_at = %s WHERE id = %d",
            (float) $signed, current_time('mysql'), $id
        ));
        if ($ok === false) {
            return new WP_Error('fin_db_balance', 'No se pudo actualizar el saldo de la cuenta.');
        }
        return (float) $wpdb->get_var($wpdb->prepare("SELECT balance FROM $t WHERE id = %d", $id));
    }

    /** Soft toggle: activa ↔ inactiva. */
    public static function toggle_active($id)
    {
        $id  = (int) $id;
        $row = self::get($id);
        if (!$row) {
            return new WP_Error('fin_account_not_found', 'Cuenta no encontrada.');
        }
        global $wpdb;
        $t   = FIN_Schema::table('accounts');
        $new = ((int) $row['active'] === 1) ? 0 : 1;
        $ok  = $wpdb->update($t,
            ['active' => $new, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );
        if ($ok === false) {
            return new WP_Error('fin_db_update', 'No se pudo cambiar el estado de la cuenta.');
        }
        return $new;
    }

    public static function type_label($type)
    {
        return $type === 'efectivo' ? 'Efectivo' : 'Banco';
    }
}
